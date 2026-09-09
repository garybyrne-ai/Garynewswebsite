<?php
declare(strict_types=1);

/**
 * Compatibility entry point for hosts whose web root is the project root
 * (for example Cloudways, where the application lives in public_html/).
 *
 * Preferred setup is to point the web root at public/ (Cloudways: Application Settings →
 * General → Webroot → "public_html/public"). When that is not possible, this file together
 * with the root .htaccess serves public/ transparently: static assets are streamed from
 * public/assets and every other request goes to the front controller.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicDir = realpath(__DIR__ . '/public');
$file = $publicDir !== false && $path !== '/' ? realpath($publicDir . $path) : false;
if ($file !== false && str_starts_with($file, $publicDir . DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file, '.php')) {
    $types = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'txt' => 'text/plain; charset=utf-8', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
require __DIR__ . '/public/index.php';
