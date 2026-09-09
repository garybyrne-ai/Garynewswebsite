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

use MeNews\Auth;
use MeNews\Config;
use MeNews\Database;

try {
    foreach (['pdo_sqlite', 'curl', 'mbstring', 'fileinfo', 'simplexml'] as $ext) {
        if (!extension_loaded($ext)) {
            throw new RuntimeException('Enable the PHP extension: ' . $ext);
        }
    }
    $email = strtolower(trim(Config::get('ADMIN_EMAIL')));
    $password = Config::get('ADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Set ADMIN_EMAIL in .env before setup.');
    }
    if (Config::production() && (strlen($password) < 12 || $password === 'ChangeMe123!')) {
        throw new RuntimeException('In production set a unique ADMIN_PASSWORD of at least 12 characters in .env.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Set ADMIN_PASSWORD (8+ characters) in .env before setup.');
    }

    $storage = Config::storage();
    $path = Database::path();
    $fresh = !is_file($path);
    if ($fresh) {
        touch($path);
    }
    Database::migrate();

    $admin = Database::one('SELECT * FROM users WHERE email=?', [$email]);
    if (!$admin) {
        $id = uuid();
        Database::insert('users', [
            'id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => 'ME News Administrator', 'handle' => 'newsroom', 'role' => 'admin', 'is_verified' => 1, 'plan' => 'ME+',
            'title' => 'Managing Editor', 'desk' => 'National', 'accent' => '158', 'reputation' => 100, 'created_at' => now(),
        ]);
        Database::insert('subscriptions', ['user_id' => $id, 'email' => $email, 'plan' => 'ME+', 'status' => 'active', 'created_at' => now()]);
        echo "Administrator created: {$email}\n";
    } elseif (in_array('--reset-admin', $argv, true)) {
        Database::query("UPDATE users SET password_hash=?,role='admin' WHERE id=?", [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        Database::query('DELETE FROM sessions WHERE user_id=?', [$admin['id']]);
        echo "Administrator password reset: {$email}\n";
    }

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
