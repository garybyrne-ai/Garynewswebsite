<?php
declare(strict_types=1);

namespace MeNews;

use MeNews\Http\HttpException;
use MeNews\Http\Request;

/**
 * Session handling. A login creates a random bearer token whose SHA-256 hash is stored in
 * the sessions table. The same token is also set as an HttpOnly cookie so that
 * server-rendered pages know who is signed in. Mutating requests authenticated by cookie
 * must carry the X-Requested-With: MENews header (CSRF guard).
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;
    private static string $via = '';
    private static ?Request $request = null;

    public static function boot(Request $request): void
    {
        self::$request = $request;
        self::$resolved = false;
        self::$user = null;
    }

    public static function cookieName(): string
    {
        return Config::get('SESSION_COOKIE', 'me_session');
    }

    public static function token(): string
    {
        $r = self::$request;
        if (!$r) {
            return '';
        }
        $t = $r->bearer();
        if ($t !== '') {
            self::$via = 'bearer';
            return $t;
        }
        $t = $r->cookie(self::cookieName());
        if ($t !== '') {
            self::$via = 'cookie';
        }
        return $t;
    }

    /** Signed-in user or null. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $token = self::token();
        if ($token === '' || !Database::installed()) {
            return null;
        }
        $u = Database::one(
            'SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?',
            [hash('sha256', $token), now()]
        );
        if ($u) {
            self::$user = $u;
        }
        return self::$user;
    }

    /** Require a signed-in user, optionally restricted to a set of roles. */
    public static function require(array $roles = []): array
    {
        $u = self::user();
        if (!$u) {
            throw new HttpException(401, 'Sign in required or session expired');
        }
        if (self::$via === 'cookie' && self::$request && !in_array(self::$request->method, ['GET', 'HEAD'], true) && !self::$request->isFetch()) {
            throw new HttpException(403, 'Cross-site request blocked');
        }
        if ($roles && !in_array($u['role'], $roles, true)) {
            throw new HttpException(403, 'Insufficient permissions');
        }
        return $u;
    }

    public static function isStaff(?array $u): bool
    {
        return $u !== null && in_array($u['role'], ['editor', 'admin'], true);
    }

    /** Create a session for a user and return the raw token. Also sets the cookie. */
    public static function login(array $user): string
    {
        $token = bin2hex(random_bytes(40));
        $days = max(1, Config::int('SESSION_DAYS', 30));
        Database::insert('sessions', [
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
            'created_at' => now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s', time() + $days * 86400) . '+00:00',
        ]);
        Database::query('UPDATE users SET last_seen_at=? WHERE id=?', [now(), $user['id']]);
        Database::query('DELETE FROM sessions WHERE expires_at<?', [now()]);
        self::setCookie($token, time() + $days * 86400);
        return $token;
    }

    public static function logout(): void
    {
        $token = self::token();
        if ($token !== '') {
            Database::query('DELETE FROM sessions WHERE token_hash=?', [hash('sha256', $token)]);
        }
        self::setCookie('', time() - 3600);
        self::$user = null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::cookieName(), $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => self::$request?->isSecure() ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Verify a password, accepting legacy Django-style PBKDF2 hashes from V3. */
    public static function verifyPassword(string $password, string $hash): bool
    {
        if (!str_starts_with($hash, 'pbkdf2_sha256$')) {
            return password_verify($password, $hash);
        }
        $parts = explode('$', $hash);
        if (count($parts) !== 4 || !ctype_digit($parts[1]) || (int)$parts[1] > 2000000) {
            return false;
        }
        $salt = base64_decode(strtr($parts[2], '-_', '+/'), true);
        $expected = base64_decode(strtr($parts[3], '-_', '+/'), true);
        return $salt !== false && $expected !== false
            && hash_equals($expected, hash_pbkdf2('sha256', $password, $salt, (int)$parts[1], 32, true));
    }

    /** Strip private fields before sending a user object to the client. */
    public static function publicUser(array $u): array
    {
        unset($u['password_hash']);
        $u['is_verified'] = (bool)$u['is_verified'];
        return $u;
    }
}
