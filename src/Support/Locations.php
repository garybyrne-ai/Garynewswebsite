<?php
declare(strict_types=1);

namespace MeNews\Support;

use MeNews\Config;
use MeNews\Services\Remote;

/**
 * Indian place data: regions, states/UTs and towns. Uses the bundled fallback list in
 * config/locations.json (built from the GeoNames "IN" export — see refreshOfficial()), or the
 * fuller official dataset once imported into STORAGE/data/india_locations.json.
 *
 * The public method names here still say "county"/"province" for historical reasons (they are
 * called from two dozen places across the codebase) but the data behind them is now Indian
 * states/union territories grouped into regions, and towns are Indian cities/towns.
 */
final class Locations
{
    private static ?array $map = null;
    private static ?array $rows = null;

    /** Town names that collide with common words or ambiguous short forms in headlines. Empty for now — add entries as false positives turn up in the wire. */
    private const TOWN_STOPLIST = [];

    private static function map(): array
    {
        return self::$map ??= json_decode(file_get_contents(ME_ROOT . '/config/locations.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array<string>> region => states/UTs */
    public static function provinces(): array
    {
        return self::map()['ZONE_STATES'];
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

    /** GeoNames admin1 code → state/UT name (the "IN" country export at download.geonames.org). */
    private const ADMIN1_STATES = [
        '01' => 'Andaman and Nicobar Islands',
        '02' => 'Andhra Pradesh',
        '03' => 'Assam',
        '05' => 'Chandigarh',
        '07' => 'Delhi',
        '09' => 'Gujarat',
        '10' => 'Haryana',
        '11' => 'Himachal Pradesh',
        '12' => 'Jammu and Kashmir',
        '13' => 'Kerala',
        '14' => 'Lakshadweep',
        '16' => 'Maharashtra',
        '17' => 'Manipur',
        '18' => 'Meghalaya',
        '19' => 'Karnataka',
        '20' => 'Nagaland',
        '21' => 'Odisha',
        '22' => 'Puducherry',
        '23' => 'Punjab',
        '24' => 'Rajasthan',
        '25' => 'Tamil Nadu',
        '26' => 'Tripura',
        '28' => 'West Bengal',
        '29' => 'Sikkim',
        '30' => 'Arunachal Pradesh',
        '31' => 'Mizoram',
        '33' => 'Goa',
        '34' => 'Bihar',
        '35' => 'Madhya Pradesh',
        '36' => 'Uttar Pradesh',
        '37' => 'Chhattisgarh',
        '38' => 'Jharkhand',
        '39' => 'Uttarakhand',
        '40' => 'Telangana',
        '41' => 'Ladakh',
        '52' => 'Dadra and Nagar Haveli and Daman and Diu',
    ];

    public static function officialFile(): string
    {
        return Config::storage() . '/data/india_locations.json';
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
     * Guess a state / town from free text such as a headline. Returns
     * ['county' => ?, 'town' => ?] with nulls when nothing is recognised (the key names stay
     * "county"/"town" for compatibility with every caller; the values are state and city names).
     */
    public static function infer(string $text): array
    {
        $county = null;
        $town = null;
        $counties = self::countyNames();
        foreach ($counties as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $text)) {
                $county = $c;
                break;
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

    /** Strip combining diacritics (GeoNames' Indian place names carry macrons like "Thāne"; we want the everyday spelling "Thane"). */
    private static function stripDiacritics(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
        return preg_replace('/\p{Mn}/u', '', $n) ?? $s;
    }

    /**
     * Download and parse GeoNames' free "IN" country export (download.geonames.org/export/dump/IN.zip,
     * no key required) into the fuller official place list. Populated places (feature class P) are
     * grouped by state/UT, state and country capitals are always kept, and each state keeps its
     * ~40 largest remaining towns by population. Returns the rows imported.
     */
    public static function refreshOfficial(): array
    {
        $zipBytes = Remote::get('https://download.geonames.org/export/dump/IN.zip', [], 90);
        $tmpZip = tempnam(sys_get_temp_dir(), 'in_geonames_') . '.zip';
        file_put_contents($tmpZip, $zipBytes);
        $zip = new \ZipArchive();
        $ok = $zip->open($tmpZip) === true;
        $txt = $ok ? $zip->getFromName('IN.txt') : false;
        if ($ok) {
            $zip->close();
        }
        @unlink($tmpZip);
        if ($txt === false || $txt === '') {
            throw new \RuntimeException('Official location service unavailable');
        }

        $byState = [];
        foreach (explode("\n", $txt) as $line) {
            if ($line === '') {
                continue;
            }
            $f = explode("\t", $line);
            if (count($f) < 15 || $f[6] !== 'P') {
                continue; // feature class P = populated place
            }
            $state = self::ADMIN1_STATES[$f[10]] ?? null;
            if ($state === null) {
                continue;
            }
            $name = self::stripDiacritics(trim($f[1]));
            if ($name === '') {
                continue;
            }
            $byState[$state][] = ['town' => $name, 'lat' => (float)$f[4], 'lon' => (float)$f[5], 'code' => $f[7], 'population' => (int)$f[14]];
        }
        if (!$byState) {
            throw new \RuntimeException('No official locations returned');
        }

        $rows = [];
        foreach ($byState as $state => $places) {
            $best = [];
            foreach ($places as $p) {
                if (!isset($best[$p['town']]) || $p['population'] > $best[$p['town']]['population']) {
                    $best[$p['town']] = $p;
                }
            }
            $places = array_values($best);
            usort($places, static function (array $a, array $b): int {
                $capA = in_array($a['code'], ['PPLC', 'PPLA'], true) ? 1 : 0;
                $capB = in_array($b['code'], ['PPLC', 'PPLA'], true) ? 1 : 0;
                return $capB <=> $capA ?: $b['population'] <=> $a['population'];
            });
            foreach (array_slice($places, 0, 40) as $p) {
                $rows[] = ['town' => $p['town'], 'county' => $state, 'province' => self::provinceFor($state), 'code' => $p['code'], 'source' => 'GeoNames (IN export)'];
            }
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
