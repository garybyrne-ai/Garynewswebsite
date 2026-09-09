<?php
/**
 * Router for PHP's built-in development server:
 *   php -S 127.0.0.1:8000 -t public public/router.php
 * Serves static files from public/ directly and routes everything else to index.php.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . $path);
if ($file !== false && $path !== '/' && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/index.php';
