<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * A lightweight, no-third-party proof-of-work challenge for the guest report form — the one
 * place an anonymous visitor can post without an account. It does not stop a determined
 * attacker, but it raises the cost of a zero-effort scripted flood far above the existing
 * per-IP rate limit alone, with no external CAPTCHA service, no CDN script and so no CSP change.
 *
 * The browser solves it with the native Web Crypto API (SubtleCrypto.digest), asking for a
 * SHA-256(challenge:nonce) hash with `difficulty` leading zero hex nibbles — a few hundred
 * thousand hashes at most, well under a second, and each challenge is single-use and short-lived.
 */
final class ProofOfWork
{
    public static function difficulty(): int
    {
        return max(3, min(7, (int)(Database::setting('pow_difficulty', '5') ?? 5)));
    }

    /** Issue a fresh challenge and prune old ones. */
    public static function issue(): array
    {
        $id = bin2hex(random_bytes(16));
        Database::insert('pow_challenges', [
            'id' => $id, 'difficulty' => self::difficulty(), 'created_at' => now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s', time() + 600) . '+00:00',
        ]);
        Database::query('DELETE FROM pow_challenges WHERE expires_at<?', [now()]);
        return ['id' => $id, 'difficulty' => self::difficulty()];
    }

    /** Verify and consume (single-use) a solved challenge. Throws on failure. */
    public static function verify(string $id, string $nonce): void
    {
        if ($id === '' || $nonce === '' || strlen($nonce) > 64) {
            throw new HttpException(400, 'Missing verification — reload the form and try again.');
        }
        $row = Database::one('SELECT * FROM pow_challenges WHERE id=? AND expires_at>?', [$id, now()]);
        if (!$row) {
            throw new HttpException(400, 'Verification expired — reload the form and try again.');
        }
        Database::query('DELETE FROM pow_challenges WHERE id=?', [$id]);
        $hash = hash('sha256', $id . ':' . $nonce);
        $needed = str_repeat('0', (int)$row['difficulty']);
        if (!str_starts_with($hash, $needed)) {
            throw new HttpException(400, 'Verification failed — reload the form and try again.');
        }
    }
}
