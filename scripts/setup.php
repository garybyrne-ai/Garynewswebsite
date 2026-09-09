<?php
declare(strict_types=1);

/**
 * Install or upgrade ME News Ireland.
 *
 *   php scripts/setup.php            create storage, database, schema and the administrator
 *   php scripts/setup.php --seed     also seed the five contributors and the news wire
 *   php scripts/setup.php --reset-admin   reset the administrator password from .env
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Config;
use MeNews\Database;
use MeNews\Services\Installer;

try {
    if ($missing = Installer::missingExtensions()) {
        throw new RuntimeException('Enable the PHP extension(s): ' . implode(', ', $missing));
    }
    $email = strtolower(trim(Config::get('ADMIN_EMAIL')));
    $password = Config::get('ADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Set ADMIN_EMAIL in .env before setup.');
    }
    if ($problem = Installer::passwordProblem($password, Config::production())) {
        throw new RuntimeException('ADMIN_PASSWORD: ' . $problem);
    }
    $storage = Config::storage();
    $path = Database::path();
    $fresh = Installer::createDatabase();
    $state = Installer::ensureAdmin($email, $password, in_array('--reset-admin', $argv, true));
    echo ['created' => "Administrator created: {$email}\n", 'reset' => "Administrator password reset: {$email}\n", 'exists' => "Administrator already exists: {$email}\n"][$state];

    echo "Setup complete.\n  Document root : " . ME_ROOT . "/public\n  Database      : {$path}\n  Storage       : {$storage}\n";

    if (in_array('--seed', $argv, true)) {
        require __DIR__ . '/seed.php';
    } elseif ($fresh) {
        echo "\nNext: php scripts/seed.php   (adds the contributors and today's Irish news)\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
