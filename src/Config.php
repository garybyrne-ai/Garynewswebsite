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

    public static function appName(): string
    {
        return self::get('APP_NAME', 'ME News Ireland');
    }
}
