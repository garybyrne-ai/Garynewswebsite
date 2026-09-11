<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Stories;

/**
 * The Signal — ME News' own ranking.
 *
 * Readers don't just upvote: they say WHY a story matters (⚡ Matters, 🔥 Talking point,
 * 💚 Good news, 🔎 Needs digging). Each vote carries a weight:
 *
 *   weight = signal weight × voter weight × local boost
 *     signal weight : Matters 1.4 · Needs digging 1.2 · Good news 1.1 · Talking point 1.0
 *     voter weight  : signed-in 1.25 · anonymous 1.0
 *     local boost   : 1.5 when the voter's county (Near-me) matches the story's county
 *
 * A story's Signal score for a window is then
 *
 *   signal = (Σ weights + 2 × confirmations + 0.5 × comments + 2 × votes in the last 3 h)
 *            ÷ (hours since publication + 4) ^ 1.2
 *
 * so fresh, locally backed, fast-moving stories rise, and everything decays. The formula is
 * published on /signal because a ranking readers can't inspect isn't one they should trust.
 */
final class Signal
{
    public const SIGNALS = [
        'matters' => ['label' => 'Matters', 'icon' => '⚡', 'weight' => 1.4, 'colour' => '#139a5c', 'blurb' => 'Important for Ireland'],
        'talking' => ['label' => 'Talking point', 'icon' => '🔥', 'weight' => 1.0, 'colour' => '#ff4d6d', 'blurb' => 'Everyone will be discussing this'],
        'good' => ['label' => 'Good news', 'icon' => '💚', 'weight' => 1.1, 'colour' => '#26c072', 'blurb' => 'A story that lifts the day'],
        'digging' => ['label' => 'Needs digging', 'icon' => '🔎', 'weight' => 1.2, 'colour' => '#ffb547', 'blurb' => 'Reporters should look closer'],
    ];
    public const VOTER_SIGNED_IN = 1.25;
    public const LOCAL_BOOST = 1.5;
    public const GRAVITY = 1.2;
    public const COOKIE = 'me_voter';

    // ------------------------------------------------------------- identity

    /** Stable voter key: the user id when signed in, otherwise an anonymous cookie (set if missing). */
    public static function voterKey(?array $user): string
    {
        if ($user) {
            return 'u:' . $user['id'];
        }
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $raw)) {
            $raw = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                setcookie(self::COOKIE, $raw, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
            }
            $_COOKIE[self::COOKIE] = $raw;
        }
        return 'a:' . $raw;
    }

    // ------------------------------------------------------------- voting

    /** Cast, change or withdraw (same signal twice) a vote. Returns the story's live tallies. */
    public static function vote(string $storyId, string $signal, ?array $user, ?string $voterCounty, string $ip): array
    {
        if (!isset(self::SIGNALS[$signal])) {
            throw new HttpException(400, 'Unknown signal');
        }
        $story = Database::one("SELECT id,county,published_at,created_at FROM stories WHERE id=? AND status='published'", [$storyId]);
        if (!$story) {
            throw new HttpException(404, 'Story not found');
        }
        RateLimiter::hit($ip . '|vote', 60, 3600, 'That is a lot of votes for one hour — take a breather.');
        $key = self::voterKey($user);
        $local = $voterCounty && $story['county'] && strcasecmp($voterCounty, $story['county']) === 0;
        $weight = self::SIGNALS[$signal]['weight'] * ($user ? self::VOTER_SIGNED_IN : 1.0) * ($local ? self::LOCAL_BOOST : 1.0);
        $existing = Database::one('SELECT id,signal FROM votes WHERE story_id=? AND voter_key=?', [$storyId, $key]);
        $mine = $signal;
        if ($existing && $existing['signal'] === $signal) {
            Database::query('DELETE FROM votes WHERE id=?', [$existing['id']]);
            $mine = null;
        } elseif ($existing) {
            Database::query('UPDATE votes SET signal=?,weight=?,county=?,user_id=?,updated_at=? WHERE id=?', [$signal, $weight, $voterCounty, $user['id'] ?? null, now(), $existing['id']]);
        } else {
            Database::insert('votes', ['story_id' => $storyId, 'voter_key' => $key, 'user_id' => $user['id'] ?? null, 'signal' => $signal, 'county' => $voterCounty, 'weight' => $weight, 'created_at' => now()]);
        }
        $tally = self::refreshStory($storyId);
        $tally['mine'] = $mine;
        $tally['local'] = $local;
        self::invalidate();
        return $tally;
    }

    /** Recompute and denormalise a story's tallies onto the stories row. */
    public static function refreshStory(string $storyId): array
    {
        $counts = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach (Database::all('SELECT signal, COUNT(*) AS n FROM votes WHERE story_id=? GROUP BY signal', [$storyId]) as $r) {
            $counts[$r['signal']] = (int)$r['n'];
        }
        $total = array_sum($counts);
        Database::query('UPDATE stories SET votes_total=?,signal_json=? WHERE id=?', [$total, json_encode($counts), $storyId]);
        $story = Database::one('SELECT * FROM stories WHERE id=?', [$storyId]);
        $score = $story ? self::scoreFor($story, 'week') : 0.0;
        return ['story_id' => $storyId, 'counts' => $counts, 'total' => $total, 'score' => $score, 'top' => self::topSignal($counts)];
    }

    public static function myVote(string $storyId, ?array $user): ?string
    {
        $key = $user ? 'u:' . $user['id'] : ('a:' . (string)($_COOKIE[self::COOKIE] ?? ''));
        $row = Database::one('SELECT signal FROM votes WHERE story_id=? AND voter_key=?', [$storyId, $key]);
        return $row['signal'] ?? null;
    }

    public static function tally(string $storyId): array
    {
        $story = Database::one('SELECT * FROM stories WHERE id=?', [$storyId]);
        $counts = $story && $story['signal_json'] ? (json_decode($story['signal_json'], true) ?: []) : [];
        $counts += array_fill_keys(array_keys(self::SIGNALS), 0);
        return ['story_id' => $storyId, 'counts' => $counts, 'total' => (int)($story['votes_total'] ?? 0), 'score' => $story ? self::scoreFor($story, 'week') : 0.0, 'top' => self::topSignal($counts)];
    }

    public static function topSignal(array $counts): ?string
    {
        arsort($counts);
        $k = array_key_first($counts);
        return $k !== null && $counts[$k] > 0 ? $k : null;
    }

    // ------------------------------------------------------------- scoring

    private static function windowSince(string $window): string
    {
        $seconds = ['today' => 86400, 'week' => 7 * 86400, 'rising' => 6 * 3600, 'all' => 90 * 86400][$window] ?? 86400;
        return gmdate('Y-m-d\TH:i:s', time() - $seconds) . '+00:00';
    }

    /** Score one story from its votes in a window (used for single-story readouts). */
    public static function scoreFor(array $story, string $window = 'today'): float
    {
        $since = self::windowSince($window);
        $agg = Database::one("SELECT COALESCE(SUM(weight),0) AS w, COUNT(*) AS n, SUM(CASE WHEN created_at>? THEN 1 ELSE 0 END) AS recent FROM votes WHERE story_id=? AND created_at>?", [gmdate('Y-m-d\TH:i:s', time() - 3 * 3600) . '+00:00', $story['id'], $since]) ?: ['w' => 0, 'n' => 0, 'recent' => 0];
        $conf = Database::count('SELECT COUNT(*) FROM confirmations WHERE story_id=?', [$story['id']]);
        $comm = Database::count("SELECT COUNT(*) FROM comments WHERE story_id=? AND status='published'", [$story['id']]);
        return self::formula((float)$agg['w'], (int)$agg['recent'], $conf, $comm, $story['published_at'] ?: $story['created_at']);
    }

    public static function formula(float $weights, int $recent, int $confirmations, int $comments, string $publishedAt): float
    {
        $hours = max(0.0, (time() - (int)strtotime($publishedAt)) / 3600);
        $raw = $weights + 2 * $confirmations + 0.5 * $comments + 2 * $recent;
        return round($raw / pow($hours + 4, self::GRAVITY) * 100, 2);
    }

    /**
     * Ranked stories for a window: today | week | rising. Cached for 30 s.
     * @return array<int,array> presented stories with signal fields and rank
     */
    public static function leaderboard(string $window = 'today', int $limit = 12, string $county = ''): array
    {
        $window = in_array($window, ['today', 'week', 'rising', 'all'], true) ? $window : 'today';
        $cacheFile = Config::storage() . '/cache/signal-' . $window . '-' . ($county !== '' ? slugify($county) : 'all') . '.json';
        if (is_file($cacheFile) && time() - filemtime($cacheFile) < 30) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_slice($cached, 0, $limit);
            }
        }
        $since = self::windowSince($window);
        $recentSince = gmdate('Y-m-d\TH:i:s', time() - 3 * 3600) . '+00:00';
        $sql = "SELECT v.story_id, SUM(v.weight) AS w, COUNT(*) AS n, SUM(CASE WHEN v.created_at>? THEN 1 ELSE 0 END) AS recent,
                  COUNT(DISTINCT v.county) AS counties
                FROM votes v JOIN stories s ON s.id=v.story_id AND s.status='published'
                WHERE v.created_at>?" . ($county !== '' ? ' AND s.county=?' : '') . ' GROUP BY v.story_id';
        $args = [$recentSince, $since];
        if ($county !== '') {
            $args[] = $county;
        }
        $rows = Database::all($sql, $args);
        $out = [];
        foreach ($rows as $r) {
            $story = Stories::byId($r['story_id']);
            if (!$story) {
                continue;
            }
            $conf = Database::count('SELECT COUNT(*) FROM confirmations WHERE story_id=?', [$story['id']]);
            $comm = Database::count("SELECT COUNT(*) FROM comments WHERE story_id=? AND status='published'", [$story['id']]);
            $score = $window === 'rising'
                ? round(((int)$r['recent'] * 3 + (float)$r['w']) / pow(max(0.0, (time() - (int)strtotime($story['time'])) / 3600) + 1, 0.5) * 100, 2)
                : self::formula((float)$r['w'], (int)$r['recent'], $conf, $comm, $story['time']);
            $story = self::decorate($story);
            $story['signal_score'] = $score;
            $story['signal_votes_window'] = (int)$r['n'];
            $story['signal_recent'] = (int)$r['recent'];
            $story['signal_counties'] = (int)$r['counties'];
            $out[] = $story;
        }
        usort($out, static fn($a, $b) => $b['signal_score'] <=> $a['signal_score']);
        $out = array_slice($out, 0, 40);
        foreach ($out as $i => &$s) {
            $s['signal_rank'] = $i + 1;
        }
        unset($s);
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return array_slice($out, 0, $limit);
    }

    /** Add signal fields (counts, top signal, share) to a presented story. */
    public static function decorate(array $story): array
    {
        if (isset($story['signal_counts'], $story['signal_mix'])) {
            return $story; // already decorated by Stories::present()
        }
        $counts = $story['signal_json'] ?? null;
        $counts = is_string($counts) ? (json_decode($counts, true) ?: []) : (is_array($counts) ? $counts : []);
        $counts += array_fill_keys(array_keys(self::SIGNALS), 0);
        $total = max(0, (int)($story['votes_total'] ?? 0));
        $story['signal_counts'] = $counts;
        $story['signal_total'] = $total;
        $story['signal_top'] = self::topSignal($counts);
        $story['signal_mix'] = [];
        foreach (self::SIGNALS as $k => $meta) {
            $story['signal_mix'][$k] = $total > 0 ? round($counts[$k] / $total * 100) : 0;
        }
        unset($story['signal_json']);
        return $story;
    }

    /** Fill a short list with view-based trending stories when voting is still warming up. */
    public static function featured(int $limit = 8, string $window = 'today'): array
    {
        $rows = self::leaderboard($window, $limit);
        if (count($rows) < $limit) {
            $seen = array_column($rows, 'id');
            foreach (Stories::trending($limit * 2) as $t) {
                if (count($rows) >= $limit) {
                    break;
                }
                if (in_array($t['id'], $seen, true)) {
                    continue;
                }
                $t = self::decorate($t);
                $t['signal_score'] = 0.0;
                $t['signal_rank'] = null;
                $t['signal_warmup'] = true;
                $rows[] = $t;
            }
        }
        return $rows;
    }

    /** Site-wide numbers for the Signal page. */
    public static function stats(): array
    {
        $day = gmdate('Y-m-d\TH:i:s', time() - 86400) . '+00:00';
        $sig = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach (Database::all('SELECT signal, COUNT(*) AS n FROM votes WHERE created_at>? GROUP BY signal', [$day]) as $r) {
            $sig[$r['signal']] = (int)$r['n'];
        }
        return [
            'votes_today' => Database::count('SELECT COUNT(*) FROM votes WHERE created_at>?', [$day]),
            'votes_all' => Database::count('SELECT COUNT(*) FROM votes'),
            'voters_today' => Database::count('SELECT COUNT(DISTINCT voter_key) FROM votes WHERE created_at>?', [$day]),
            'stories_today' => Database::count('SELECT COUNT(DISTINCT story_id) FROM votes WHERE created_at>?', [$day]),
            'counties' => Database::all("SELECT county, COUNT(*) AS n FROM votes WHERE created_at>? AND county IS NOT NULL AND county<>'' GROUP BY county ORDER BY n DESC LIMIT 8", [$day]),
            'signals_today' => $sig,
        ];
    }

    private static function invalidate(): void
    {
        foreach (glob(Config::storage() . '/cache/signal-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }
}
