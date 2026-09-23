<?php
declare(strict_types=1);

namespace MeNews;

/**
 * Minimal configuration loader. Reads KEY=value lines from a .env file and exposes
 * typed accessors. Real environment variables always win over the .env file.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static ?string $base = null;
    private static bool $resolving = false;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    /**
     * The canonical site address used for canonical tags, feeds, sitemaps, emails and payment
     * return URLs. Precedence: the newsroom's Site address setting, then PUBLIC_BASE_URL from
     * .env, then the address the visitor actually used. A configured value whose host does not
     * match the live request is ignored for web requests, so moving to a real domain cannot
     * leave every URL pointing at an old staging address.
     */
    public static function baseUrl(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $fromRequest = self::requestBase();
        if (self::$resolving) {
            // Re-entered while the database was opening: answer without touching it again.
            return $fromRequest ?: self::normaliseBase(self::get('PUBLIC_BASE_URL'));
        }
        self::$resolving = true;
        try {
            $setting = Database::installed() ? trim((string)Database::setting('site_url', '')) : '';
        } catch (\Throwable $e) {
            $setting = ''; // during install, or before the settings table exists
        } finally {
            self::$resolving = false;
        }
        $candidate = self::normaliseBase($setting) ?: self::normaliseBase(self::get('PUBLIC_BASE_URL'));
        if ($candidate === '') {
            return self::$base = $fromRequest;
        }
        // Explicit newsroom setting always wins; a stale .env value loses to the live host.
        if ($setting === '' && $fromRequest !== '' && parse_url($candidate, PHP_URL_HOST) !== parse_url($fromRequest, PHP_URL_HOST)) {
            return self::$base = $fromRequest;
        }
        return self::$base = $candidate;
    }

    /** Forget the memoised base (used after the setting changes, and by tests). */
    public static function forgetBaseUrl(): void
    {
        self::$base = null;
    }

    /** scheme://host[:port] for the current request, or '' on the command line. */
    private static function requestBase(): string
    {
        // The requested host first; the forwarded header only when a proxy hid it.
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https://' : 'http://') . $host;
    }

    /** Accept "example.ie", "https://example.ie/" etc.; reject anything that is not a web address. */
    public static function normaliseBase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://') && !preg_match('~^https?://~i', $value)) {
            return ''; // only web addresses
        }
        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }
        $parts = parse_url(rtrim($value, '/'));
        if (!$parts || empty($parts['host']) || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key, (string)$default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function production(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    public static function storage(): string
    {
        $path = self::get('STORAGE_PATH', ME_ROOT . '/storage');
        foreach (['', '/data', '/uploads/quarantine', '/uploads/public', '/logs', '/cache'] as $dir) {
            if (!is_dir($path . $dir)) {
                @mkdir($path . $dir, 0750, true);
            }
        }
        return $path;
    }

    /** Secret for signing short-lived URLs. APP_KEY, else CRON_KEY, else derived from the install path. */
    public static function secret(): string
    {
        $k = self::get('APP_KEY') ?: self::get('CRON_KEY');
        return $k !== '' ? $k : hash('sha256', 'menews|' . ME_ROOT . '|' . (self::$values['ADMIN_EMAIL'] ?? ''));
    }

    public static function appName(): string
    {
        return self::get('APP_NAME', 'ME News Ireland');
    }
}
