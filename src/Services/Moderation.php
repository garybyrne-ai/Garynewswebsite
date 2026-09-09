<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use RuntimeException;

/**
 * Safety screening. Uses the OpenAI moderation endpoint when a key is configured. In
 * development a conservative keyword screen keeps the product demonstrable offline.
 * Safety is never a truth signal: the newsroom decides verification labels.
 */
final class Moderation
{
    public static function screen(string $text, array $images = []): array
    {
        if (Config::get('OPENAI_API_KEY') === '') {
            if (Config::production()) {
                throw new RuntimeException('OpenAI moderation is not configured');
            }
            $flagged = (bool)preg_match('/graphic blood|explicit nude|home address is|kill them|terrorist attack instructions/i', $text);
            return ['provider' => 'local-dev-fallback', 'flagged' => $flagged, 'note' => 'Development-only screening'];
        }
        $input = [['type' => 'text', 'text' => mb_substr($text, 0, 30000)]];
        foreach (array_slice($images, 0, 16) as $file) {
            $input[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . mime_content_type($file) . ';base64,' . base64_encode((string)file_get_contents($file))]];
        }
        $raw = Remote::json(
            'https://api.openai.com/v1/moderations',
            ['model' => Config::get('OPENAI_MODERATION_MODEL', 'omni-moderation-latest'), 'input' => $input],
            ['Authorization: Bearer ' . Config::get('OPENAI_API_KEY')]
        );
        if (empty($raw['results'])) {
            throw new RuntimeException('Moderation returned no results');
        }
        $flagged = false;
        foreach ($raw['results'] as $r) {
            if (!array_key_exists('flagged', $r)) {
                throw new RuntimeException('Incomplete moderation');
            }
            $flagged = $flagged || (bool)$r['flagged'];
        }
        return ['provider' => 'openai', 'flagged' => $flagged, 'raw' => $raw];
    }

    public static function transcribe(string $audioFile): string
    {
        if (Config::get('OPENAI_API_KEY') === '') {
            if (Config::production()) {
                throw new RuntimeException('Audio transcription is not configured');
            }
            return '';
        }
        $j = Remote::json(
            'https://api.openai.com/v1/audio/transcriptions',
            ['model' => Config::get('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'), 'file' => new \CURLFile($audioFile, 'audio/mpeg', 'audio.mp3')],
            ['Authorization: Bearer ' . Config::get('OPENAI_API_KEY')],
            'multipart'
        );
        if (!isset($j['text'])) {
            throw new RuntimeException('Audio transcription is incomplete');
        }
        return (string)$j['text'];
    }
}
