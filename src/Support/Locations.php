<?php
declare(strict_types=1);

namespace MeNews\Support;

use MeNews\Config;
use MeNews\Services\Remote;

/**
 * Irish place data: provinces, counties and towns. Uses the bundled fallback list in
 * config/locations.json, or the official CSO / Tailte Éireann 2022 urban areas layer once
 * imported into STORAGE/data/ireland_locations.json.
 */
final class Locations
{
    private static ?array $map = null;
    private static ?array $rows = null;

    /** Words that are also towns but far more often something else in headlines. */
    private const TOWN_STOPLIST = ['Trim', 'Swords', 'Virginia', 'Newport', 'Clara', 'Moate', 'Bandon', 'Rush', 'Lusk', 'Tallow', 'Borris', 'Gort', 'Cahir', 'Boyle', 'Ferbane', 'Portlaw', 'Athy', 'Naas'];

    private static function map(): array
    {
        return self::$map ??= json_decode(file_get_contents(ME_ROOT . '/config/locations.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array<string>> province => counties */
    public static function provinces(): array
    {
        return self::map()['PROVINCE_COUNTIES'];
    }

    /** @return array<int,array{province:string,county:string}> */
    public static function counties(): array
    {
        $out = [];
        foreach (self::provinces() as $province => $counties) {
            foreach ($counties as $county) {
                $out[] = ['province' => $province, 'county' => $county];
            }
        }
        return $out;
    }

    public static function countyNames(): array
    {
        return array_column(self::counties(), 'county');
    }

    public static function provinceFor(string $county): string
    {
        foreach (self::provinces() as $province => $counties) {
            if (in_array($county, $counties, true)) {
                return $province;
            }
        }
        return '';
    }

    public static function officialFile(): string
    {
        return Config::storage() . '/data/ireland_locations.json';
    }

    public static function source(): string
    {
        return is_file(self::officialFile()) ? 'official' : 'fallback';
    }

    /** @return array<int,array{town:string,county:string,province:string,source:string}> */
    public static function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        $file = self::officialFile();
        if (is_file($file)) {
            $rows = json_decode((string)file_get_contents($file), true);
            if (is_array($rows) && $rows) {
                return self::$rows = $rows;
            }
        }
        $out = [];
        foreach (self::map()['FALLBACK_TOWNS'] as $county => $towns) {
            foreach ($towns as $town) {
                $out[] = ['town' => $town, 'county' => $county, 'province' => self::provinceFor($county), 'source' => 'fallback'];
            }
        }
        return self::$rows = $out;
    }

    public static function search(string $q, string $county = '', int $limit = 80): array
    {
        $q = mb_strtolower($q);
        $county = mb_strtolower($county);
        $out = [];
        foreach (self::all() as $row) {
            $hay = mb_strtolower($row['town'] . ' ' . $row['county'] . ' ' . $row['province']);
            if (($q === '' || str_contains($hay, $q)) && ($county === '' || str_contains(mb_strtolower($row['county']), $county))) {
                $out[] = $row;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Guess a county / town from free text such as a headline. Returns
     * ['county' => ?, 'town' => ?] with nulls when nothing is recognised.
     */
    public static function infer(string $text): array
    {
        $county = null;
        $town = null;
        $counties = self::countyNames();
        // "Co Mayo", "Co. Donegal", "County Clare"
        if (preg_match('/\b(?:Co\.?|County)\s+([A-Z][a-zé]+)\b/u', $text, $m) && in_array($m[1], $counties, true)) {
            $county = $m[1];
        }
        if ($county === null) {
            foreach ($counties as $c) {
                if (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $text)) {
                    $county = $c;
                    break;
                }
            }
        }
        $best = 0;
        foreach (self::map()['FALLBACK_TOWNS'] as $c => $towns) {
            if ($county !== null && $c !== $county) {
                continue;
            }
            foreach ($towns as $t) {
                if (in_array($t, self::TOWN_STOPLIST, true) || in_array($t, $counties, true)) {
                    continue;
                }
                $len = mb_strlen($t);
                if ($len > $best && preg_match('/(?<![\p{L}])' . preg_quote($t, '/') . '(?![\p{L}])/u', $text)) {
                    $best = $len;
                    $town = $t;
                    $county ??= $c;
                }
            }
        }
        return ['county' => $county, 'town' => $town];
    }

    /** Download the official CSO / Tailte Éireann urban areas list. Returns the rows imported. */
    public static function refreshOfficial(): array
    {
        $url = 'https://services-eu1.arcgis.com/BuS9rtTsYEV5C0xh/ArcGIS/rest/services/Urban_Areas_National_Statistical_Boundaries_2022_Ungeneralised_View/FeatureServer/0/query';
        $rows = [];
        $seen = [];
        for ($offset = 0; $offset < 20000; $offset += 2000) {
            $payload = Remote::json($url . '?' . http_build_query([
                'where' => '1=1', 'outFields' => 'URBAN_AREA_CODE,URBAN_AREA_NAME,COUNTY', 'returnGeometry' => 'false',
                'f' => 'json', 'resultRecordCount' => 2000, 'resultOffset' => $offset, 'orderByFields' => 'URBAN_AREA_CODE',
            ]));
            if (isset($payload['error']) || !isset($payload['features'])) {
                throw new \RuntimeException('Official location service unavailable');
            }
            foreach ($payload['features'] as $feature) {
                $a = $feature['attributes'];
                $town = trim((string)($a['URBAN_AREA_NAME'] ?? ''));
                $county = trim((string)($a['COUNTY'] ?? ''));
                $key = mb_strtolower($town . '|' . $county);
                if ($town === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = ['town' => $town, 'county' => $county, 'province' => self::provinceFor($county), 'code' => $a['URBAN_AREA_CODE'] ?? null, 'source' => 'CSO/Tailte Éireann 2022'];
            }
            if (empty($payload['exceededTransferLimit'])) {
                break;
            }
        }
        if (!$rows) {
            throw new \RuntimeException('No official locations returned');
        }
        usort($rows, static fn($a, $b) => [$a['county'], $a['town']] <=> [$b['county'], $b['town']]);
        $file = self::officialFile();
        $temp = $file . '.' . uuid() . '.tmp';
        file_put_contents($temp, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        rename($temp, $file);
        self::$rows = null;
        return $rows;
    }
}
