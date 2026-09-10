<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use Throwable;

/**
 * Today's weather across Ireland from Open-Meteo (free, no key). Cached for 30 minutes;
 * a stale cache is used when the service is unreachable so pages never wait.
 */
final class Weather
{
    public const CITIES = [
        ['Dublin', 53.35, -6.26], ['Cork', 51.90, -8.47], ['Galway', 53.27, -9.05], ['Limerick', 52.66, -8.63],
        ['Belfast', 54.60, -5.93], ['Sligo', 54.27, -8.47], ['Waterford', 52.26, -7.11], ['Letterkenny', 54.95, -7.73],
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

    private static function fetch(): array
    {
        $lat = implode(',', array_map(static fn($c) => (string)$c[1], self::CITIES));
        $lng = implode(',', array_map(static fn($c) => (string)$c[2], self::CITIES));
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lng,
            'current' => 'temperature_2m,weather_code,wind_speed_10m',
            'daily' => 'sunrise,sunset,temperature_2m_max,temperature_2m_min,weather_code',
            'timezone' => 'Europe/Dublin', 'forecast_days' => 1,
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
        $dublin = $rows[0];
        return [
            'updated' => now(),
            'sunrise' => substr((string)($dublin['daily']['sunrise'][0] ?? ''), 11, 5),
            'sunset' => substr((string)($dublin['daily']['sunset'][0] ?? ''), 11, 5),
            'cities' => $cities,
            'source' => 'Open-Meteo',
        ];
    }
}
