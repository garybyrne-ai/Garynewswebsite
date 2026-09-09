<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;
use MeNews\Http\HttpException;

/** Persistent per-key rate limiting in fixed windows (survives restarts; no trust in proxies). */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds, string $message = 'Too many attempts. Try again shortly.'): void
    {
        $bucket = (int)floor(time() / $windowSeconds);
        $hash = hash('sha256', $key);
        Database::query('INSERT INTO rate_limits(key,bucket,hits) VALUES(?,?,1) ON CONFLICT(key,bucket) DO UPDATE SET hits=hits+1', [$hash, $bucket]);
        $hits = Database::count('SELECT hits FROM rate_limits WHERE key=? AND bucket=?', [$hash, $bucket]);
        Database::query('DELETE FROM rate_limits WHERE bucket<?', [$bucket - 1]);
        if ($hits > $max) {
            throw new HttpException(429, $message);
        }
    }
}
