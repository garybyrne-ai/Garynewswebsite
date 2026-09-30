<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Support\Geo;
use Throwable;

/**
 * Today's weather across India from Open-Meteo (free, no key). Cached for 30 minutes;
 * a stale cache is used when the service is unreachable so pages never wait.
 */
final class Weather
{
    public const CITIES = [
        ['Delhi', 28.61, 77.21], ['Mumbai', 19.08, 72.88], ['Bengaluru', 12.97, 77.59], ['Kolkata', 22.57, 88.36],
        ['Chennai', 13.08, 80.27], ['Hyderabad', 17.39, 78.49], ['Jaipur', 26.91, 75.79], ['Guwahati', 26.14, 91.74],
    ];

    private const CODES = [
        0 => ['Clear', '☀'], 1 => ['Mostly clear', '🌤'], 2 => ['Partly cloudy', '⛅'], 3 => ['Overcast', '☁'],
        45 => ['Fog', '🌫'], 48 => ['Fog', '🌫'], 51 => ['Drizzle', '🌦'], 53 => ['Drizzle', '🌦'], 55 => ['Drizzle', '🌦'],
        56 => ['Freezing drizzle', '🌧'], 57 => ['Freezing drizzle', '🌧'], 61 => ['Light rain', '🌧'], 63 => ['Rain', '🌧'], 65 => ['Heavy rain', '🌧'],
        66 => ['Freezing rain', '🌧'], 67 => ['Freezing rain', '🌧'], 71 => ['Light snow', '🌨'], 73 => ['Snow', '🌨'], 75 => ['Heavy snow', '❄'],
        77 => ['Snow grains', '🌨'], 80 => ['Showers', '🌦'], 81 => ['Showers', '🌦'], 82 => ['Heavy showers', '⛈'],
        85 => ['Snow showers', '🌨'], 86 => ['Snow showers', '🌨'], 95 => ['Thunderstorm', '⛈'], 96 => ['Thunderstorm', '⛈'], 99 => ['Thunderstorm', '⛈'],
    ];

    private static function cacheFile(): string
    {
        return Config::storage() . '/cache/weather.json';
    }

    /** @return array{updated:string,sunrise:string,sunset:string,cities:array}|null */
    public static function today(): ?array
    {
        $file = self::cacheFile();
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 1800) {
            return $cached;
        }
        try {
            $fresh = self::fetch();
            file_put_contents($file, json_encode($fresh, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $fresh;
        } catch (Throwable $e) {
            error_log('Weather: ' . $e->getMessage());
            if (is_array($cached)) {
                // Stamp so we do not hammer the API while it is down.
                $cached['updated'] = now();
                $cached['stale'] = true;
                file_put_contents($file, json_encode($cached, JSON_UNESCAPED_UNICODE), LOCK_EX);
                return $cached;
            }
            return null;
        }
    }

    /**
     * Live weather for one state (its centroid — usually near the capital), for the state page.
     * Cached per state for 30 minutes; returns null rather than guessing when the fetch fails.
     * @return array{temp:int,max:int,min:int,label:string,icon:string}|null
     */
    public static function forState(string $state): ?array
    {
        $point = Geo::county($state);
        if (!$point) {
            return null;
        }
        $file = Config::storage() . '/cache/weather-' . slugify($state) . '.json';
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 1800) {
            return $cached;
        }
        try {
            $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
                'latitude' => $point[0], 'longitude' => $point[1],
                'current' => 'temperature_2m,weather_code',
                'daily' => 'temperature_2m_max,temperature_2m_min',
                'timezone' => 'Asia/Kolkata', 'forecast_days' => 1,
            ]);
            $row = json_decode(Remote::get($url, ['Accept: application/json'], 8), true, 512, JSON_THROW_ON_ERROR);
            $code = (int)($row['current']['weather_code'] ?? 3);
            [$label, $icon] = self::CODES[$code] ?? ['Cloudy', '☁'];
            $fresh = [
                'updated' => now(),
                'temp' => (int)round((float)($row['current']['temperature_2m'] ?? 0)),
                'max' => (int)round((float)($row['daily']['temperature_2m_max'][0] ?? 0)),
                'min' => (int)round((float)($row['daily']['temperature_2m_min'][0] ?? 0)),
                'label' => $label, 'icon' => $icon,
            ];
            file_put_contents($file, json_encode($fresh, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $fresh;
        } catch (Throwable $e) {
            error_log('Weather (state ' . $state . '): ' . $e->getMessage());
            return is_array($cached) ? $cached : null;
        }
    }

    private static function fetch(): array
    {
        $lat = implode(',', array_map(static fn($c) => (string)$c[1], self::CITIES));
        $lng = implode(',', array_map(static fn($c) => (string)$c[2], self::CITIES));
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lng,
            'current' => 'temperature_2m,weather_code,wind_speed_10m',
            'daily' => 'sunrise,sunset,temperature_2m_max,temperature_2m_min,weather_code',
            'timezone' => 'Asia/Kolkata', 'forecast_days' => 1,
        ]);
        $raw = Remote::get($url, ['Accept: application/json'], 8);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $rows = isset($data[0]) ? $data : [$data];
        $cities = [];
        foreach ($rows as $i => $row) {
            $code = (int)($row['current']['weather_code'] ?? 3);
            [$label, $icon] = self::CODES[$code] ?? ['Cloudy', '☁'];
            $cities[] = [
                'name' => self::CITIES[$i][0],
                'temp' => (int)round((float)($row['current']['temperature_2m'] ?? 0)),
                'wind' => (int)round((float)($row['current']['wind_speed_10m'] ?? 0)),
                'max' => (int)round((float)($row['daily']['temperature_2m_max'][0] ?? 0)),
                'min' => (int)round((float)($row['daily']['temperature_2m_min'][0] ?? 0)),
                'label' => $label, 'icon' => $icon, 'code' => $code,
            ];
        }
        $primary = $rows[0];
        return [
            'updated' => now(),
            'sunrise' => substr((string)($primary['daily']['sunrise'][0] ?? ''), 11, 5),
            'sunset' => substr((string)($primary['daily']['sunset'][0] ?? ''), 11, 5),
            'cities' => $cities,
            'source' => 'Open-Meteo',
        ];
    }
}
