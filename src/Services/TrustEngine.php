<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use Throwable;

/**
 * The ME Trust Engine screens community submissions before any editor sees them:
 * media is probed, video frames and audio are sampled, everything is moderated and two
 * separate scores are produced. Safety != truth: publication and labels stay editorial.
 */
final class TrustEngine
{
    public static function process(string $id): array
    {
        $s = Database::one('SELECT * FROM stories WHERE id=?', [$id]);
        if (!$s) {
            throw new HttpException(404, 'Report not found');
        }
        Database::query("UPDATE stories SET status='processing',safety_score=0,moderation_json=NULL,media_public=NULL,updated_at=? WHERE id=?", [now(), $id]);
        try {
            set_time_limit(600);
            $frames = [];
            $transcript = '';
            if ($s['media_original']) {
                $file = Media::path($s['media_original'], 'quarantine');
                if ($s['media_type'] === 'image') {
                    $frames = [$file];
                } else {
                    [$frames, $transcript] = self::sampleVideo($file, $id);
                }
            }
            $mod = Moderation::screen(implode("\n", [$s['title'], $s['body'] ?? '', $transcript, $s['location_name'] ?? '', $s['county'] ?? '', $s['local_area'] ?? '']), $frames);
            $author = Database::one('SELECT is_verified FROM users WHERE id=?', [$s['author_user_id']]);
            $len = mb_strlen(trim(($s['body'] ?? '') . $transcript));
            $trust = min(82, 38 + ($len > 80 ? 8 : 0) + ($len > 250 ? 5 : 0) + ($s['media_original'] ? 9 : 0)
                + ($s['latitude'] !== null && $s['longitude'] !== null ? 6 : 0) + (!empty($author['is_verified']) ? 8 : 0));
            $safety = $mod['flagged'] ? 18 : 97;
            $status = $mod['flagged'] ? 'hold' : (Config::bool('AUTO_PUBLISH_SAFE') && !empty($s['reporter_verified_at']) ? 'published' : 'review');
            $public = null;
            $publishedAt = $s['published_at'];
            if ($status === 'published') {
                self::gate(['safety_score' => $safety, 'moderation_json' => json_encode($mod)]);
                $public = Media::publish($s);
                $publishedAt = now();
            }
            Database::query(
                'UPDATE stories SET transcript=?,moderation_json=?,safety_score=?,trust_score=?,status=?,media_public=?,published_at=?,editorial_note=NULL,updated_at=? WHERE id=?',
                [$transcript, json_encode($mod), $safety, $trust, $status, $public, $publishedAt, now(), $id]
            );
            Notifier::send($s['author_user_id'], 'report-status', 'Your report was safety screened', "Status: {$status}. Safety {$safety}/100. Confidence {$trust}/100.", $id);
            if ($status === 'published' && $s['status'] !== 'published') {
                Notifier::localAlerts($s);
                Notifier::reportStatus($s, 'published');
                if ($s['author_user_id']) {
                    Database::query('UPDATE users SET reports_published=reports_published+1 WHERE id=?', [$s['author_user_id']]);
                }
            }
        } catch (Throwable $e) {
            error_log('Trust engine ' . $id . ': ' . $e->getMessage());
            Database::query(
                "UPDATE stories SET status='review',safety_score=0,moderation_json=NULL,media_public=NULL,editorial_note=?,updated_at=? WHERE id=?",
                ['Automated screening was unavailable. Re-run safety screening before publication.', now(), $id]
            );
            Notifier::send($s['author_user_id'], 'report-status', 'Your report is awaiting review', 'Safety screening is pending.', $id);
        }
        return Database::one('SELECT id,slug,status,safety_score,trust_score FROM stories WHERE id=?', [$id]) ?? [];
    }

    /** Extract up to 16 frames across the timeline plus a transcript of the audio track. */
    private static function sampleVideo(string $file, string $id): array
    {
        $probe = json_decode(Media::command([Config::get('FFPROBE_BIN', 'ffprobe'), '-v', 'error', '-show_format', '-show_streams', '-of', 'json', $file]), true, 512, JSON_THROW_ON_ERROR);
        $duration = (float)($probe['format']['duration'] ?? 0);
        if ($duration <= 0 || $duration > Config::int('MAX_VIDEO_SECONDS', 600)) {
            throw new \RuntimeException('Invalid or overlong video');
        }
        $dir = Config::storage() . '/uploads/quarantine/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        Media::command([Config::get('FFMPEG_BIN', 'ffmpeg'), '-nostdin', '-y', '-i', $file, '-vf', 'fps=1/' . max(1, $duration / 16) . ',scale=960:-2', '-frames:v', '16', $dir . '/frame_%03d.jpg']);
        $frames = glob($dir . '/frame_*.jpg') ?: [];
        if (!$frames) {
            throw new \RuntimeException('No video frames extracted');
        }
        $transcript = '';
        $hasAudio = count(array_filter($probe['streams'] ?? [], static fn($s) => ($s['codec_type'] ?? '') === 'audio')) > 0;
        if ($hasAudio) {
            $audio = $dir . '/audio.mp3';
            Media::command([Config::get('FFMPEG_BIN', 'ffmpeg'), '-nostdin', '-y', '-i', $file, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', $audio]);
            $transcript = Moderation::transcribe($audio);
        }
        return [$frames, $transcript];
    }

    /** Refuse publication unless a complete, clean screening exists (OpenAI in production). */
    public static function gate(array $story): void
    {
        $m = json_decode($story['moderation_json'] ?? '{}', true) ?: [];
        if (($story['safety_score'] ?? 0) <= 0 || !array_key_exists('flagged', $m) || $m['flagged']) {
            throw new HttpException(409, 'A complete, clean safety screening is required before publication.');
        }
        if (Config::production() && ($m['provider'] ?? '') !== 'openai') {
            throw new HttpException(409, 'Production publication requires OpenAI moderation.');
        }
    }
}
