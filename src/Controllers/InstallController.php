<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Installer;
use MeNews\View;
use Throwable;

/**
 * Browser installer. Only reachable while the database does not exist yet; once installed
 * the route disappears, so the window in which an unauthenticated visitor could install
 * the site is limited to the moments between deployment and first setup.
 */
final class InstallController
{
    public static function guard(): void
    {
        if (Database::installed()) {
            throw new HttpException(404, 'ME News is already installed.');
        }
    }

    private static function defaultBase(Request $r): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($r->isSecure() || str_contains($host, 'cloudwaysapps.com') ? 'https' : 'http') . '://' . $host;
    }

    public static function form(Request $r): Response
    {
        self::guard();
        return Response::html(View::render('install', [
            'missing' => Installer::missingExtensions(),
            'values' => [
                'PUBLIC_BASE_URL' => Config::get('PUBLIC_BASE_URL', '') ?: self::defaultBase($r),
                'ADMIN_EMAIL' => Config::get('ADMIN_EMAIL', ''),
                'APP_ENV' => Config::get('APP_ENV', 'production'),
            ],
            'writable' => is_writable(ME_ROOT) || (is_file(ME_ROOT . '/.env') && is_writable(ME_ROOT . '/.env')),
            'storageWritable' => is_writable(ME_ROOT) || is_writable(Config::storage()),
            'error' => null,
            'result' => null,
        ], null));
    }

    public static function run(Request $r): Response
    {
        self::guard();
        set_time_limit(300);
        $env = $r->post('app_env', 'production', 20) === 'development' ? 'development' : 'production';
        $base = rtrim($r->post('public_base_url', '', 300), '/');
        $email = strtolower($r->post('admin_email', '', 254));
        $password = (string)($r->rawPost('admin_password') ?? '');
        $contributorPassword = (string)($r->rawPost('contributor_password') ?? '');
        $live = $r->post('load_live', '1') === '1';
        $errors = [];
        if (!filter_var($base, FILTER_VALIDATE_URL) || !preg_match('~^https?://~', $base)) {
            $errors[] = 'Enter the full site address, e.g. https://phpstack-1234723-6658079.cloudwaysapps.com';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid administrator email address.';
        }
        if ($p = Installer::passwordProblem($password, $env === 'production')) {
            $errors[] = $p;
        }
        if ($contributorPassword !== '' && strlen($contributorPassword) < 8) {
            $errors[] = 'Contributor password must be at least 8 characters (or leave it blank to generate random ones).';
        }
        if ($missing = Installer::missingExtensions()) {
            $errors[] = 'Enable these PHP extensions first: ' . implode(', ', $missing);
        }
        $result = null;
        if (!$errors) {
            try {
                Installer::writeEnv([
                    'APP_ENV' => $env, 'PUBLIC_BASE_URL' => $base, 'ADMIN_EMAIL' => $email,
                    'ADMIN_PASSWORD' => $password, 'CONTRIBUTOR_PASSWORD' => $contributorPassword,
                    'CRON_KEY' => Config::get('CRON_KEY') ?: bin2hex(random_bytes(16)),
                ]);
                Installer::createDatabase();
                Installer::ensureAdmin($email, $password, true);
                $contributors = Installer::seedContributors($contributorPassword);
                $wire = Installer::loadWire($live);
                $result = [
                    'email' => $email,
                    'contributors' => $contributors,
                    'wire' => $wire,
                    'stories' => Database::count("SELECT COUNT(*) FROM stories WHERE status='published'"),
                    'base' => $base,
                    'cron_url' => $base . '/cron/wire?key=' . rawurlencode(Config::get('CRON_KEY')),
                ];
            } catch (Throwable $e) {
                error_log('Install failed: ' . $e);
                $errors[] = $e->getMessage();
                // Do not leave a half-installed database behind.
                if (is_file(Database::path()) && !Database::installed()) {
                    @unlink(Database::path());
                }
            }
        }
        return Response::html(View::render('install', [
            'missing' => Installer::missingExtensions(),
            'values' => ['PUBLIC_BASE_URL' => $base, 'ADMIN_EMAIL' => $email, 'APP_ENV' => $env],
            'writable' => true,
            'storageWritable' => true,
            'error' => $errors ? implode(' ', $errors) : null,
            'result' => $result,
        ], null), $errors ? 422 : 200);
    }
}
