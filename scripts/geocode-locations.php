<?php
declare(strict_types=1);

/**
 * Build config/geo.json — coordinates for every county and town in config/locations.json —
 * using OpenStreetMap Nominatim (one request per second, as its usage policy requires).
 * Run once when the location list changes:  php scripts/geocode-locations.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Services\Remote;
use MeNews\Support\Locations;

$out = ME_ROOT . '/config/geo.json';
$geo = is_file($out) ? (json_decode((string)file_get_contents($out), true) ?: []) : [];
$geo += ['counties' => [], 'towns' => []];
$queries = [];
foreach (Locations::countyNames() as $county) {
    if (!isset($geo['counties'][$county])) {
        $queries[] = ['counties', $county, 'County ' . $county . ', Ireland'];
    }
}
foreach (Locations::all() as $row) {
    $key = $row['town'] . '|' . $row['county'];
    if (!isset($geo['towns'][$key])) {
        $queries[] = ['towns', $key, $row['town'] . ', County ' . $row['county'] . ', Ireland'];
    }
}
echo count($queries) . " places to geocode\n";
foreach ($queries as $i => [$bucket, $key, $q]) {
    try {
        $res = Remote::json('https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $q, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'ie']), null, ['Accept: application/json']);
        if (!empty($res[0]['lat'])) {
            $geo[$bucket][$key] = [round((float)$res[0]['lat'], 4), round((float)$res[0]['lon'], 4)];
            echo str_pad((string)($i + 1), 4) . $q . ' → ' . implode(', ', $geo[$bucket][$key]) . "\n";
        } else {
            echo str_pad((string)($i + 1), 4) . $q . " → not found\n";
        }
    } catch (Throwable $e) {
        echo str_pad((string)($i + 1), 4) . $q . ' → ERROR ' . $e->getMessage() . "\n";
    }
    if ($i % 10 === 9) {
        ksort($geo['counties']);
        ksort($geo['towns']);
        file_put_contents($out, json_encode($geo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    sleep(1);
}
ksort($geo['counties']);
ksort($geo['towns']);
file_put_contents($out, json_encode($geo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'Saved ' . count($geo['counties']) . ' counties and ' . count($geo['towns']) . " towns to config/geo.json\n";
