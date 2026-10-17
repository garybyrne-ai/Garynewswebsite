<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use Throwable;

/**
 * Live cricket scores. There is no reliable free, keyless public API for this — every real
 * provider (CricAPI, Cricbuzz via RapidAPI, SportMonks…) requires a registered key even on
 * their free tier. This wires up CricAPI's free tier (100 requests/day, enough for a
 * cached ticker) the same way AQI and mandi prices are wired: inactive and silent until an
 * admin adds a key under Settings → Live data, at https://cricapi.com (free signup).
 */
final class Cricket
{
    public static function configured(): bool
    {
        return Database::setting('cricket_api_key', '') !== '';
    }

    /** @return array<int, array{teams:string,status:string,score:string}> */
    public static function liveMatches(): array
    {
        $key = (string)Database::setting('cricket_api_key', '');
        if ($key === '') {
            return [];
        }
        $file = Config::storage() . '/cache/cricket-live.json';
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 300) {
            return $cached['rows'];
        }
        try {
            $url = 'https://api.cricapi.com/v1/currentMatches?' . http_build_query(['apikey' => $key, 'offset' => 0]);
            $row = json_decode(Remote::get($url, ['Accept: application/json'], 8), true, 512, JSON_THROW_ON_ERROR);
            $rows = [];
            foreach ((array)($row['data'] ?? []) as $m) {
                if (empty($m['matchStarted']) || !empty($m['matchEnded'])) {
                    continue;
                }
                $teams = implode(' vs ', (array)($m['teams'] ?? []));
                $score = [];
                foreach ((array)($m['score'] ?? []) as $s) {
                    $score[] = ($s['inning'] ?? '') . ': ' . ($s['r'] ?? '?') . '/' . ($s['w'] ?? '?') . ' (' . ($s['o'] ?? '?') . ')';
                }
                $rows[] = ['teams' => $teams, 'status' => (string)($m['status'] ?? ''), 'score' => implode(' · ', $score)];
                if (count($rows) >= 5) {
                    break;
                }
            }
            file_put_contents($file, json_encode(['updated' => now(), 'rows' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $rows;
        } catch (Throwable $e) {
            error_log('Cricket: ' . $e->getMessage());
            return is_array($cached) ? $cached['rows'] : [];
        }
    }
}
