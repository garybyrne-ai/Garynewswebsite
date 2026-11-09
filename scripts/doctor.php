<?php
declare(strict_types=1);

/**
 * ME News deployment doctor.
 *
 *   php scripts/doctor.php
 *   php scripts/doctor.php /story/some-slug      # render one page and report what it does
 *
 * Run it on the server after a deploy when pages return 500. It checks the things that
 * actually break a deploy — missing files, an out-of-date database, permissions, PHP
 * version and extensions — and prints the last fatal error from the log. It only reads;
 * nothing is changed. Safe to run on a live site.
 *
 * Given a path, it builds that exact page in this process and prints the error, the time
 * and the peak memory. If the process dies instead of printing, the shell says so
 * ("Segmentation fault" / "Killed") and that is the answer: the PHP worker is crashing,
 * not returning an error, which is what nginx reports as a reset while reading the header.
 */

$root = dirname(__DIR__);
$problems = [];
$warnings = [];
$target = $argv[1] ?? null;

function line(string $label, string $state, string $detail = ''): void
{
    $mark = ['ok' => '  ✔ ', 'bad' => '  ✘ ', 'warn' => '  ! '][$state];
    echo $mark . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

if ($target !== null) {
    require $root . '/src/bootstrap.php';
    $path = '/' . ltrim(parse_url($target, PHP_URL_PATH) ?: '/', '/');
    echo "ME News page check\n\n  PHP " . PHP_VERSION . ' (' . PHP_SAPI . ")\n  path " . $path . "\n\n";
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['HTTP_HOST'] = (string)(parse_url(\MeNews\Config::baseUrl(), PHP_URL_HOST) ?: 'localhost');
    $_SERVER['HTTP_USER_AGENT'] = 'ME News doctor';
    $started = microtime(true);
    try {
        $request = new \MeNews\Http\Request();
        \MeNews\Auth::boot($request);
        $router = new \MeNews\Http\Router();
        (require $root . '/src/routes.php')($router);
        ob_start();
        $response = $router->dispatch($request);
        ob_end_clean();
        $body = (string)($response->body ?? '');
        printf("  rendered: HTTP %d, %d bytes in %.0f ms, peak memory %.1f MB\n",
            $response->status ?? 0, strlen($body), (microtime(true) - $started) * 1000, memory_get_peak_usage(true) / 1048576);
        if (($response->status ?? 0) >= 300 && ($response->status ?? 0) < 400) {
            echo '  redirects to: ' . ($response->headers['Location'] ?? '?') . "\n";
        }
        echo "\n  The application renders this page without error, so a 500 from the web server\n";
        echo "  is coming from somewhere else: a page cache holding an old response, the webroot,\n";
        echo "  .htaccess, or a PHP-FPM limit (memory_limit, request_terminate_timeout).\n";
        exit(0);
    } catch (Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        printf("  FAILED after %.0f ms, peak memory %.1f MB\n\n", (microtime(true) - $started) * 1000, memory_get_peak_usage(true) / 1048576);
        echo '  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
        foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 12) as $frame) {
            echo '    ' . $frame . "\n";
        }
        exit(1);
    }
}

echo "ME News deployment doctor\n\nRuntime\n";

// --- PHP ---
$php = PHP_VERSION;
$phpOk = version_compare($php, '8.2.0', '>=');
line('PHP ' . $php, $phpOk ? 'ok' : 'bad', $phpOk ? '' : 'ME News needs PHP 8.2 or newer');
if (!$phpOk) {
    $problems[] = 'PHP ' . $php . ' is too old. Set the site to PHP 8.2 or newer (Cloudways: Application Settings → PHP version).';
}
foreach (['pdo_sqlite', 'curl', 'mbstring', 'fileinfo', 'simplexml', 'sodium', 'gd'] as $ext) {
    $has = extension_loaded($ext);
    $needed = in_array($ext, ['pdo_sqlite', 'curl', 'mbstring', 'fileinfo', 'simplexml'], true);
    line('ext ' . $ext, $has ? 'ok' : ($needed ? 'bad' : 'warn'), $has ? '' : ($needed ? 'required' : 'optional, some features degrade'));
    if (!$has && $needed) {
        $problems[] = 'The PHP extension ' . $ext . ' is not installed.';
    }
}

// --- Files: a half-finished upload is the usual cause of a 500 on every page ---
echo "\nApplication files\n";
$required = [
    'src/bootstrap.php', 'src/helpers.php', 'src/routes.php', 'src/Database.php', 'src/Migrations.php',
    'src/Config.php', 'src/Stories.php', 'src/View.php', 'src/Ui.php',
    'src/Services/Seo.php', 'src/Support/Crawler.php', 'src/Support/Lang.php', 'src/Support/Share.php',
    'src/Services/Membership.php', 'src/Services/Feeds.php', 'src/Services/Media.php', 'src/Services/Secrets.php',
    'src/Controllers/PageController.php', 'src/Controllers/AdminController.php',
    'templates/layout.php', 'templates/story.php', 'templates/about.php',
    'database/schema.sql', 'public/index.php', 'public/.htaccess',
    'public/assets/css/menews.css', 'public/assets/js/menews.js', 'public/assets/js/newsroom.js',
    'public/assets/vendor/leaflet/leaflet.js', 'public/assets/vendor/leaflet/leaflet.css',
];
$missing = [];
foreach ($required as $rel) {
    if (!is_file($root . '/' . $rel)) {
        $missing[] = $rel;
    }
}
if ($missing) {
    foreach ($missing as $m) {
        line($m, 'bad', 'missing');
    }
    $problems[] = count($missing) . ' file(s) did not make it onto the server. Upload the whole release, not only the files that changed: '
        . 'a new class that is missing takes every page down with a 500. Missing: ' . implode(', ', $missing);
} else {
    line('all ' . count($required) . ' required files present', 'ok');
}

// Every PHP file must parse. A truncated upload shows up here.
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() === 'php') {
        $out = [];
        $code = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $bad[] = str_replace($root . '/', '', $f->getPathname()) . ': ' . trim(implode(' ', $out));
        }
    }
}
line(count($bad) === 0 ? 'every file under src/ parses' : count($bad) . ' file(s) under src/ do not parse', $bad ? 'bad' : 'ok', implode(' | ', array_slice($bad, 0, 3)));
if ($bad) {
    $problems[] = 'A PHP file is corrupt or truncated — re-upload it: ' . implode(' | ', $bad);
}

// --- Configuration ---
echo "\nConfiguration\n";
$envFile = $root . '/.env';
line('.env present', is_file($envFile) ? 'ok' : 'bad', is_file($envFile) ? '' : 'copy .env.example to .env');
if (!is_file($envFile)) {
    $problems[] = 'There is no .env file. Copy .env.example to .env and set APP_KEY.';
}

require $root . '/src/bootstrap.php';

use MeNews\Config;
use MeNews\Database;

line('APP_ENV = ' . Config::get('APP_ENV', 'production'), 'ok');
$publicBase = Config::get('PUBLIC_BASE_URL', '');
if ($publicBase !== '') {
    line('PUBLIC_BASE_URL = ' . $publicBase, 'warn', 'the newsroom Site address setting takes precedence over this');
}

// --- Storage: the database must be writable, or every page dies on the schema upgrade ---
echo "\nStorage\n";
$storage = Config::storage();
line('storage path ' . $storage, is_dir($storage) ? 'ok' : 'bad');
if (!is_dir($storage)) {
    $problems[] = 'The storage directory does not exist: ' . $storage;
}
foreach (['data', 'cache', 'logs', 'uploads'] as $dir) {
    $path = $storage . '/' . $dir;
    $ok = is_dir($path) && is_writable($path);
    line('storage/' . $dir . ' writable', $ok ? 'ok' : 'bad', is_dir($path) ? (is_writable($path) ? '' : 'not writable by ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'the web user')) : 'missing');
    if (!$ok) {
        $problems[] = 'storage/' . $dir . ' must exist and be writable by the web server user.';
    }
}
$dbPath = Database::path();
$dbOk = is_file($dbPath) && is_writable($dbPath);
line('database file writable', $dbOk ? 'ok' : 'bad', $dbPath);
if (is_file($dbPath) && !is_writable($dbPath)) {
    $problems[] = 'The database file is not writable. A deploy that raises the schema version has to write to it, so every page returns 500 until it can. Fix the ownership of ' . $dbPath . ' and the directory holding it.';
}

// --- Database schema ---
echo "\nDatabase\n";
try {
    $pdo = Database::pdo();
    $version = (int)(Database::setting('schema_version', '0') ?? 0);
    $expected = Database::SCHEMA_VERSION;
    line('schema version ' . $version . ' of ' . $expected, $version === $expected ? 'ok' : 'bad', $version === $expected ? '' : 'the upgrade did not complete');
    if ($version !== $expected) {
        $problems[] = 'The database is still on schema version ' . $version . ' but the code expects ' . $expected . '. It upgrades itself on the first request, so this nearly always means the file is not writable.';
    }
    $cols = array_column(Database::all('PRAGMA table_info(stories)'), 'name');
    foreach (['redirect_to', 'sponsored', 'sponsor_name', 'cluster_id', 'image_hash'] as $col) {
        $has = in_array($col, $cols, true);
        line('stories.' . $col, $has ? 'ok' : 'bad', $has ? '' : 'missing');
        if (!$has) {
            $problems[] = 'The stories table is missing the ' . $col . ' column, so every listing query fails.';
        }
    }
    line('published stories: ' . (Database::one("SELECT COUNT(*) c FROM stories WHERE status='published'")['c'] ?? 0), 'ok');
    $siteUrl = (string)(Database::setting('site_url', '') ?? '');
    line('newsroom site address', $siteUrl !== '' ? 'ok' : 'warn', $siteUrl !== '' ? $siteUrl : 'not set — sitemap and canonical tags follow whichever domain the visitor used');
    if ($siteUrl !== '') {
        $redirect = (string)(Database::setting('canonical_redirect', '1') ?? '1');
        line('redirect other domains to it', 'ok', $redirect === '1' ? 'on' : 'off');
    }
} catch (Throwable $e) {
    line('database', 'bad', $e->getMessage());
    $problems[] = 'The database could not be opened or upgraded: ' . $e->getMessage();
}

// --- Rendering: ask the app to build a page in-process, which surfaces the real fatal ---
echo "\nPage rendering\n";
try {
    ob_start();
    // The error page plus the shared layout: the chrome, the navigation, the language
    // switcher and the search-engine meta tags all run, with no page data to get in the way.
    $html = (string)\MeNews\View::render('error', ['user' => null, 'nav' => \MeNews\Support\Categories::NAV,
        'status' => 200, 'message' => 'deployment doctor', 'title' => 'doctor', 'description' => 'doctor']);
    ob_end_clean();
    line('the page chrome renders', strlen($html) > 500 ? 'ok' : 'bad', strlen($html) . ' bytes');
    if (strlen($html) < 500) {
        $problems[] = 'The shared layout produced almost nothing. Check that templates/ uploaded completely.';
    }
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    line('page rendering', 'bad', get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    $problems[] = 'Rendering a page throws: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
}

// Then the same page over HTTP, which also covers the web server, .htaccess and any cache.
$selfUrl = rtrim(Config::baseUrl(), '/') . '/robots.txt';
if (function_exists('curl_init') && !str_contains($selfUrl, 'localhost')) {
    $ch = curl_init($selfUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code === 200) {
        line('GET ' . $selfUrl, 'ok');
    } elseif ($code >= 300 && $code < 400) {
        line('GET ' . $selfUrl, 'warn', 'HTTP ' . $code . ' — redirected, check the newsroom Site address');
    } else {
        line('GET ' . $selfUrl, 'bad', $code ? 'HTTP ' . $code : ($err ?: 'no response'));
        $problems[] = 'The site answers HTTP ' . ($code ?: 0) . ' over the network while the application itself renders fine. '
            . 'That points at the web server rather than the code: the webroot, .htaccess, or a page cache holding an old response.';
        if ($body !== '' && stripos($body, '<html') === false) {
            echo '    body: ' . mb_substr(trim($body), 0, 300) . "\n";
        }
    }
}

// --- The server's own error log usually names the fault outright ---
echo "\nRecent errors\n";
$logs = array_filter([ini_get('error_log') ?: null, $storage . '/logs/php-error.log', $root . '/../logs/php-app.error.log', '/var/log/php-fpm/error.log']);
$shown = false;
foreach ($logs as $log) {
    if ($log && is_file($log) && is_readable($log)) {
        $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -12);
        if ($lines) {
            echo '  from ' . $log . ":\n";
            foreach ($lines as $l) {
                echo '    ' . mb_substr($l, 0, 220) . "\n";
            }
            $shown = true;
            break;
        }
    }
}
if (!$shown) {
    echo "  No readable PHP error log found. On Cloudways look in Application → Logs, or\n";
    echo "  ~/logs/<app>.error.log over SSH.\n";
}

echo "\n" . str_repeat('-', 62) . "\n";
if ($problems) {
    echo count($problems) . " problem(s) found:\n\n";
    foreach ($problems as $i => $p) {
        echo '  ' . ($i + 1) . '. ' . $p . "\n\n";
    }
    exit(1);
}
echo "No problems found. The application itself is healthy.\n";
echo "If pages still return 500, the fault is in the web server rather than the app:\n";
echo "  - check that the webroot points at the public/ directory (or the project root, which forwards into it)\n";
echo "  - check .htaccess is being read (AllowOverride All) and that mod_rewrite is on\n";
echo "  - clear any page cache (Cloudways: Varnish / Breeze) after a deploy\n";
exit(0);
