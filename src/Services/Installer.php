<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use RuntimeException;

/**
 * Installation steps shared by the CLI (scripts/setup.php, scripts/seed.php) and the
 * browser installer (/install). Every step is idempotent.
 */
final class Installer
{
    public const REQUIRED_EXTENSIONS = ['pdo_sqlite', 'curl', 'mbstring', 'fileinfo', 'simplexml'];

    public static function missingExtensions(): array
    {
        return array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn(string $e) => !extension_loaded($e)));
    }

    /** Validate an administrator password for the current environment. Returns an error message or null. */
    public static function passwordProblem(string $password, bool $production): ?string
    {
        if (strlen($password) < 8) {
            return 'Use a password of at least 8 characters.';
        }
        if ($production && (strlen($password) < 12 || $password === 'ChangeMe123!')) {
            return 'In production the administrator password must be unique and at least 12 characters.';
        }
        return null;
    }

    /**
     * Write .env from .env.example, replacing the given keys. Existing custom values in an
     * existing .env are preserved unless overridden.
     */
    public static function writeEnv(array $values): string
    {
        $file = ME_ROOT . '/.env';
        $template = is_file($file) ? $file : ME_ROOT . '/.env.example';
        $lines = file($template, FILE_IGNORE_NEW_LINES) ?: [];
        $seen = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $m) && array_key_exists($m[1], $values)) {
                $lines[$i] = $m[1] . '=' . self::envValue((string)$values[$m[1]]);
                $seen[$m[1]] = true;
            }
        }
        foreach ($values as $k => $v) {
            if (!isset($seen[$k])) {
                $lines[] = $k . '=' . self::envValue((string)$v);
            }
        }
        $content = implode("\n", $lines) . "\n";
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write ' . $file . '. Create it by hand with this content:' . "\n\n" . $content);
        }
        @chmod($file, 0640);
        Config::load($file);
        return $file;
    }

    private static function envValue(string $v): string
    {
        return preg_match('/[\s#"\']/', $v) ? '"' . str_replace('"', '\\"', $v) . '"' : $v;
    }

    /** Create the database file and schema. Returns true when the database was newly created. */
    public static function createDatabase(): bool
    {
        Config::storage();
        $path = Database::path();
        $fresh = !is_file($path);
        if ($fresh && !@touch($path)) {
            throw new RuntimeException('Cannot create the database at ' . $path . '. Make storage/ writable.');
        }
        Database::migrate();
        return $fresh;
    }

    /** Create (or reset) the administrator account. Returns 'created', 'reset' or 'exists'. */
    public static function ensureAdmin(string $email, string $password, bool $reset = false): string
    {
        $email = strtolower(trim($email));
        $admin = Database::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$admin) {
            $id = uuid();
            Database::insert('users', [
                'id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => 'ME News Administrator', 'handle' => 'newsroom', 'role' => 'admin', 'is_verified' => 1, 'plan' => 'ME+',
                'title' => 'Managing Editor', 'desk' => 'National', 'accent' => '158', 'reputation' => 100, 'created_at' => now(),
            ]);
            Database::insert('subscriptions', ['user_id' => $id, 'email' => $email, 'plan' => 'ME+', 'status' => 'active', 'created_at' => now()]);
            return 'created';
        }
        if ($reset) {
            Database::query("UPDATE users SET password_hash=?,role='admin' WHERE id=?", [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            Database::query('DELETE FROM sessions WHERE user_id=?', [$admin['id']]);
            return 'reset';
        }
        return 'exists';
    }

    /**
     * Seed the contributor accounts from database/seed/contributors.json.
     * Returns [display_name => password|null] (null when the account already existed).
     */
    public static function seedContributors(string $sharedPassword = ''): array
    {
        $contributors = json_decode((string)file_get_contents(ME_ROOT . '/database/seed/contributors.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($contributors as $c) {
            $existing = Database::one('SELECT id FROM users WHERE email=? OR handle=?', [$c['email'], $c['handle']]);
            if ($existing) {
                Database::query('UPDATE users SET display_name=?,title=?,desk=?,home_town=?,home_county=?,bio=?,accent=?,reputation=?,role=?,is_verified=1 WHERE id=?',
                    [$c['display_name'], $c['title'], $c['desk'], $c['home_town'], $c['home_county'], $c['bio'], $c['accent'], $c['reputation'], 'contributor', $existing['id']]);
                $out[$c['display_name']] = null;
                continue;
            }
            $password = $sharedPassword !== '' ? $sharedPassword : bin2hex(random_bytes(6));
            $id = uuid();
            Database::insert('users', [
                'id' => $id, 'email' => $c['email'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $c['display_name'], 'handle' => $c['handle'], 'role' => 'contributor', 'is_verified' => 1, 'plan' => 'ME+',
                'title' => $c['title'], 'desk' => $c['desk'], 'home_town' => $c['home_town'], 'home_county' => $c['home_county'],
                'bio' => $c['bio'], 'accent' => $c['accent'], 'reputation' => $c['reputation'], 'created_at' => now(),
            ]);
            Database::insert('subscriptions', ['user_id' => $id, 'email' => $c['email'], 'plan' => 'ME+', 'status' => 'active', 'created_at' => now()]);
            $out[$c['display_name']] = $password;
        }
        return $out;
    }

    /** Import the bundled snapshot and, optionally, refresh from the live sources. */
    public static function loadWire(bool $live, ?callable $progress = null): array
    {
        $snapshot = NewsWire::importSnapshot(ME_ROOT . '/database/seed/wire-snapshot.json');
        $summary = ['snapshot' => $snapshot, 'live' => 0, 'errors' => []];
        if ($live && NewsWire::enabled()) {
            $r = NewsWire::refresh(true, $progress);
            $summary['live'] = $r['inserted'];
            $summary['errors'] = $r['errors'];
        }
        return $summary;
    }
}
