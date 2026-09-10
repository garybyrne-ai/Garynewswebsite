<?php
declare(strict_types=1);

namespace MeNews\Support;

use MeNews\Database;

/**
 * Coordinates for Irish counties and towns (config/geo.json, built from OpenStreetMap
 * Nominatim by scripts/geocode-locations.php). Used to pin wire stories on the live map.
 */
final class Geo
{
    private static ?array $data = null;

    /** Category → accent colour used for map pins and legends. */
    public const COLOURS = [
        'National' => '#47d5ff', 'Local' => '#2ef2a8', 'Business' => '#f1c76b', 'Sport' => '#9b8cff', 'Culture' => '#ff8ad4',
        'Community' => '#ffb547', 'Traffic' => '#ff4d6d', 'Council' => '#7cc4ff', "What's On" => '#c8ff5a', 'World' => '#9aa6b8',
    ];

    private static function data(): array
    {
        if (self::$data === null) {
            $file = ME_ROOT . '/config/geo.json';
            self::$data = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
            self::$data += ['counties' => [], 'towns' => []];
        }
        return self::$data;
    }

    /** @return array{0:float,1:float,2:string}|null  [lat, lng, precision: town|county] */
    public static function lookup(?string $town, ?string $county): ?array
    {
        $d = self::data();
        $town = trim((string)$town);
        $county = trim((string)$county);
        if ($town !== '' && !str_starts_with($town, 'Co.')) {
            if ($county !== '' && isset($d['towns'][$town . '|' . $county])) {
                return [...$d['towns'][$town . '|' . $county], 'town'];
            }
            foreach ($d['towns'] as $key => $pt) {
                if (str_starts_with($key, $town . '|')) {
                    return [...$pt, 'town'];
                }
            }
        }
        if ($county !== '' && isset($d['counties'][$county])) {
            return [...$d['counties'][$county], 'county'];
        }
        return null;
    }

    /** County centroid. */
    public static function county(string $county): ?array
    {
        return self::data()['counties'][$county] ?? null;
    }

    /**
     * Coordinates for a story with a deterministic scatter so stories from the same place
     * do not stack on one pixel. County-level matches scatter more widely than town-level.
     */
    public static function forStory(?string $town, ?string $county, string $seed): ?array
    {
        $hit = self::lookup($town, $county);
        if (!$hit) {
            return null;
        }
        [$lat, $lng, $precision] = $hit;
        $h = crc32($seed);
        $spread = $precision === 'town' ? 0.012 : 0.09;
        $dlat = ((($h & 0xffff) / 0xffff) - 0.5) * 2 * $spread;
        $dlng = (((($h >> 16) & 0xffff) / 0xffff) - 0.5) * 2 * $spread * 1.6;
        return [round($lat + $dlat, 5), round($lng + $dlng, 5)];
    }

    /** Give coordinates to stories that have a place but no GPS yet. Returns rows updated. */
    public static function backfill(int $limit = 2000): int
    {
        $rows = Database::all("SELECT id,location_name,county FROM stories WHERE latitude IS NULL AND (county IS NOT NULL AND county<>'' OR location_name IS NOT NULL) LIMIT ?", [$limit]);
        $n = 0;
        foreach ($rows as $r) {
            $pt = self::forStory($r['location_name'], $r['county'], $r['id']);
            if ($pt) {
                Database::query('UPDATE stories SET latitude=?,longitude=? WHERE id=?', [$pt[0], $pt[1], $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Great-circle distance in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Resolve a position to the nearest known Irish town and its county.
     * @return array{town:?string,county:?string,province:string,town_km:?float,county_km:?float,in_ireland:bool}
     */
    public static function nearest(float $lat, float $lng): array
    {
        $d = self::data();
        $bestTown = null;
        $bestTownKm = INF;
        foreach ($d['towns'] as $key => $pt) {
            $km = self::distanceKm($lat, $lng, (float)$pt[0], (float)$pt[1]);
            if ($km < $bestTownKm) {
                $bestTownKm = $km;
                $bestTown = $key;
            }
        }
        $bestCounty = null;
        $bestCountyKm = INF;
        foreach ($d['counties'] as $name => $pt) {
            $km = self::distanceKm($lat, $lng, (float)$pt[0], (float)$pt[1]);
            if ($km < $bestCountyKm) {
                $bestCountyKm = $km;
                $bestCounty = $name;
            }
        }
        $inIreland = $bestCountyKm < 120;
        $town = $inIreland && $bestTown !== null && $bestTownKm < 35 ? explode('|', $bestTown)[0] : null;
        // The nearest town is a better county guess than the nearest centroid.
        $county = $inIreland ? ($bestTown !== null && $bestTownKm < 35 ? explode('|', $bestTown)[1] : $bestCounty) : null;
        return [
            'town' => $town, 'county' => $county, 'province' => $county ? Locations::provinceFor($county) : '',
            'town_km' => $bestTownKm === INF ? null : round($bestTownKm, 1), 'county_km' => $bestCountyKm === INF ? null : round($bestCountyKm, 1),
            'in_ireland' => $inIreland,
        ];
    }

    public static function colour(string $category): string
    {
        return self::COLOURS[$category] ?? '#47d5ff';
    }
}
