<?php
declare(strict_types=1);

/**
 * Seed the five ME News contributors and load the news wire.
 *
 *   php scripts/seed.php             contributors + bundled snapshot + live wire refresh
 *   php scripts/seed.php --offline   contributors + bundled snapshot only
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!defined('ME_ROOT')) {
    require dirname(__DIR__) . '/src/bootstrap.php';
}

use MeNews\Config;
use MeNews\Database;
use MeNews\Services\NewsWire;

$argv = $argv ?? [];
try {
    if (!Database::installed()) {
        throw new RuntimeException('Run php scripts/setup.php first.');
    }
    $contributors = json_decode((string)file_get_contents(ME_ROOT . '/database/seed/contributors.json'), true, 512, JSON_THROW_ON_ERROR);
    $shared = Config::get('CONTRIBUTOR_PASSWORD');
    echo "\nContributors\n";
    foreach ($contributors as $c) {
        $existing = Database::one('SELECT id FROM users WHERE email=? OR handle=?', [$c['email'], $c['handle']]);
        if ($existing) {
            Database::query('UPDATE users SET display_name=?,title=?,desk=?,home_town=?,home_county=?,bio=?,accent=?,reputation=?,role=?,is_verified=1 WHERE id=?',
                [$c['display_name'], $c['title'], $c['desk'], $c['home_town'], $c['home_county'], $c['bio'], $c['accent'], $c['reputation'], 'contributor', $existing['id']]);
            echo "  = {$c['display_name']} (exists)\n";
            continue;
        }
        $password = $shared !== '' ? $shared : bin2hex(random_bytes(6));
        $id = uuid();
        Database::insert('users', [
            'id' => $id, 'email' => $c['email'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $c['display_name'], 'handle' => $c['handle'], 'role' => 'contributor', 'is_verified' => 1, 'plan' => 'ME+',
            'title' => $c['title'], 'desk' => $c['desk'], 'home_town' => $c['home_town'], 'home_county' => $c['home_county'],
            'bio' => $c['bio'], 'accent' => $c['accent'], 'reputation' => $c['reputation'], 'created_at' => now(),
        ]);
        Database::insert('subscriptions', ['user_id' => $id, 'email' => $c['email'], 'plan' => 'ME+', 'status' => 'active', 'created_at' => now()]);
        echo "  + {$c['display_name']}  <{$c['email']}>  password: {$password}\n";
    }

    echo "\nNews wire\n";
    $snapshot = ME_ROOT . '/database/seed/wire-snapshot.json';
    $n = NewsWire::importSnapshot($snapshot);
    echo "  Bundled snapshot: {$n} stories imported\n";

    if (!in_array('--offline', $argv, true) && NewsWire::enabled()) {
        $summary = NewsWire::refresh(true, static function (string $key, int $fetched, int $inserted, ?string $error): void {
            echo '  ' . str_pad($key, 24) . ($error ? "ERROR {$error}" : "{$fetched} fetched, {$inserted} new") . "\n";
        });
        echo "  Live refresh: {$summary['inserted']} new stories\n";
    }
    echo "\nPublished stories: " . Database::count("SELECT COUNT(*) FROM stories WHERE status='published'") . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
