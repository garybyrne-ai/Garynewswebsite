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
use MeNews\Services\Installer;

$argv = $argv ?? [];
try {
    if (!Database::installed()) {
        throw new RuntimeException('Run php scripts/setup.php first.');
    }
    echo "\nContributors\n";
    foreach (Installer::seedContributors(Config::get('CONTRIBUTOR_PASSWORD')) as $name => $password) {
        echo $password === null ? "  = {$name} (exists)\n" : "  + {$name}  password: {$password}\n";
    }
    echo "\nNews wire\n";
    $wire = Installer::loadWire(!in_array('--offline', $argv, true), static function (string $key, int $fetched, int $inserted, ?string $error): void {
        echo '  ' . str_pad($key, 24) . ($error ? "ERROR {$error}" : "{$fetched} fetched, {$inserted} new") . "\n";
    });
    echo "  Bundled snapshot: {$wire['snapshot']} stories imported\n";
    echo "  Live refresh: {$wire['live']} new stories\n";
    echo "\nPublished stories: " . Database::count("SELECT COUNT(*) FROM stories WHERE status='published'") . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
