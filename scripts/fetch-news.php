<?php
declare(strict_types=1);

/**
 * Refresh the news wire from the real Irish sources in config/sources.json.
 * Run from cron every 15–20 minutes, e.g.:
 *   (cron) 15-minute interval:  php /var/www/menews/scripts/fetch-news.php >> /var/www/menews/storage/logs/wire.log 2>&1
 *
 *   php scripts/fetch-news.php              refresh (forced)
 *   php scripts/fetch-news.php --if-stale   refresh only when older than WIRE_REFRESH_MINUTES
 *   php scripts/fetch-news.php --snapshot   also write database/seed/wire-snapshot.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Services\NewsWire;

try {
    $force = !in_array('--if-stale', $argv, true);
    $summary = NewsWire::refresh($force, static function (string $key, int $fetched, int $inserted, ?string $error): void {
        echo gmdate('H:i:s') . '  ' . str_pad($key, 24) . ($error ? "ERROR {$error}" : "{$fetched} fetched, {$inserted} new") . "\n";
    });
    if (!$summary['ran']) {
        echo "Wire is fresh (last refresh " . (NewsWire::lastRefresh() ?? 'never') . ").\n";
    } else {
        echo "Done: {$summary['fetched']} fetched, {$summary['inserted']} new stories.\n";
    }
    if (in_array('--snapshot', $argv, true)) {
        $n = NewsWire::exportSnapshot(ME_ROOT . '/database/seed/wire-snapshot.json');
        echo "Snapshot written with {$n} stories.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Wire refresh failed: ' . $e->getMessage() . "\n");
    exit(1);
}
