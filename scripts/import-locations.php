<?php
declare(strict_types=1);

/** Import the official CSO / Tailte Éireann 2022 urban areas into storage/data/ireland_locations.json */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    echo 'Imported ' . count(MeNews\Support\Locations::refreshOfficial()) . " official urban areas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
