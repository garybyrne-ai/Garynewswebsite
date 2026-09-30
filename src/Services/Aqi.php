<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Support\Geo;
use Throwable;

/**
 * Air Quality Index for a state (its centroid, same coordinates Weather::forState() uses) from
 * the World Air Quality Index project — free, but needs a personal token (instant, no cost, at
 * https://aqicn.org/data-platform/token/). Inactive — returns null, no error — until an admin
 * adds one under Settings → Live data. Cached per state for 30 minutes.
 */
final class Aqi
{
    private const BANDS = [
        [0, 50, 'Good', '#2ecc71'], [51, 100, 'Satisfactory', '#a3d900'], [101, 200, 'Moderate', '#f4c20d'],
        [201, 300, 'Poor', '#e67e22'], [301, 400, 'Very poor', '#e74c3c'], [401, 999, 'Severe', '#8e2de2'],
    ];

    public static function configured(): bool
    {
        return Database::setting('waqi_token', '') !== '';
    }

    /** @return array{aqi:int,label:string,colour:string,updated:string}|null */
    public static function forState(string $state): ?array
    {
        $token = (string)Database::setting('waqi_token', '');
        if ($token === '') {
            return null;
        }
        $point = Geo::county($state);
        if (!$point) {
            return null;
        }
        $file = Config::storage() . '/cache/aqi-' . slugify($state) . '.json';
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 1800) {
            return $cached;
        }
        try {
            $url = 'https://api.waqi.info/feed/geo:' . $point[0] . ';' . $point[1] . '/?token=' . rawurlencode($token);
            $row = json_decode(Remote::get($url, [], 8), true, 512, JSON_THROW_ON_ERROR);
            if (($row['status'] ?? '') !== 'ok' || !isset($row['data']['aqi']) || !is_numeric($row['data']['aqi'])) {
                return is_array($cached) ? $cached : null;
            }
            $aqi = (int)$row['data']['aqi'];
            $label = 'Severe';
            $colour = '#8e2de2';
            foreach (self::BANDS as [$lo, $hi, $l, $c]) {
                if ($aqi >= $lo && $aqi <= $hi) {
                    $label = $l;
                    $colour = $c;
                    break;
                }
            }
            $fresh = ['aqi' => $aqi, 'label' => $label, 'colour' => $colour, 'updated' => now()];
            file_put_contents($file, json_encode($fresh, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $fresh;
        } catch (Throwable $e) {
            error_log('AQI (state ' . $state . '): ' . $e->getMessage());
            return is_array($cached) ? $cached : null;
        }
    }
}
