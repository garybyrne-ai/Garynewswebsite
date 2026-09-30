<?php
declare(strict_types=1);

/** Import GeoNames' free "IN" (India) place export into storage/data/india_locations.json */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    echo 'Imported ' . count(MeNews\Support\Locations::refreshOfficial()) . " official towns.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
