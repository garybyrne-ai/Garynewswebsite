<?php
declare(strict_types=1);

/**
 * ME News Ireland — HTTP front controller.
 * The web server document root must be this directory (public/).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Auth;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Http\Router;
use MeNews\View;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');

$request = new Request();
Auth::boot($request);

try {
    if (!MeNews\Database::installed()) {
        // Not installed yet: offer the browser installer (or the CLI: php scripts/setup.php --seed).
        if ($request->path === '/install') {
            $handler = $request->method === 'POST' ? 'run' : 'form';
            MeNews\Controllers\InstallController::$handler($request)->send();
            exit;
        }
        if ($request->wantsJson()) {
            throw new HttpException(503, 'ME News is not installed yet. Open /install in a browser or run php scripts/setup.php --seed.');
        }
        Response::redirect('/install')->send();
        exit;
    }
    $router = new Router();
    (require ME_ROOT . '/src/routes.php')($router);
    $router->dispatch($request)->send();
    if ($request->method === 'GET' && !$request->wantsJson()) {
        MeNews\Services\NewsWire::afterResponse();
    }
} catch (HttpException $e) {
    if ($request->wantsJson()) {
        Response::json(['detail' => $e->getMessage()], $e->status)->send();
    } else {
        View::page('error', ['user' => Auth::user(), 'nav' => MeNews\Support\Categories::NAV, 'status' => $e->status, 'message' => $e->getMessage(), 'title' => $e->status . ' — ME News Ireland'], $e->status)->send();
    }
} catch (Throwable $e) {
    error_log((string)$e);
    $detail = MeNews\Config::production() ? 'The request could not be completed. Check the server error log.' : $e->getMessage();
    if ($request->wantsJson()) {
        Response::json(['detail' => $detail], 500)->send();
    } else {
        $body = '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:system-ui;background:#06080f;color:#e8ecf4;padding:40px"><h1>500</h1><p>' . e($detail) . '</p></body>';
        Response::html($body, 500)->send();
    }
}
