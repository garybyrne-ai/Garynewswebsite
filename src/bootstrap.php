<?php
declare(strict_types=1);

/**
 * ME News Ireland — application bootstrap.
 *
 * Registers the PSR-4 autoloader for the MeNews namespace, loads the .env file,
 * configures error handling and exposes a handful of global view helpers.
 * Both the HTTP front controller (public/index.php) and the CLI scripts require this file.
 */

define('ME_ROOT', dirname(__DIR__));
define('ME_VERSION', '4.2.0');
// Asset cache-buster: changes whenever any stylesheet or script changes, so a deploy never leaves
// browsers running yesterday's JavaScript against today's templates.
define('ME_ASSETS', ME_VERSION . '.' . substr(md5(implode('|', array_map('filemtime', array_merge(glob(__DIR__ . '/../public/assets/css/*.css') ?: [], glob(__DIR__ . '/../public/assets/js/*.js') ?: [])))), 0, 8));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'MeNews\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

MeNews\Config::load(getenv('ME_NEWS_ENV_FILE') ?: ME_ROOT . '/.env');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', MeNews\Config::storage() . '/logs/app.log');
umask(0027);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
