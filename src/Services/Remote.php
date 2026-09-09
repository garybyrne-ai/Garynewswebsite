<?php
declare(strict_types=1);

namespace MeNews\Services;

use CURLFile;
use MeNews\Config;
use RuntimeException;

/** Outbound HTTPS helper built on cURL (HTTPS only, sane timeouts). */
final class Remote
{
    public static function json(string $url, ?array $body = null, array $headers = [], string $encoding = 'json', int $timeout = 120): array
    {
        $raw = self::request($url, $body, $headers, $encoding, $timeout);
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid external service response');
        }
        return $json;
    }

    public static function get(string $url, array $headers = [], int $timeout = 25): string
    {
        return self::request($url, null, $headers, 'json', $timeout);
    }

    private static function request(string $url, ?array $body, array $headers, string $encoding, int $timeout): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise HTTP client');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => Config::get('WIRE_USER_AGENT', 'MENewsWire/4.0'),
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($encoding === 'json') {
                $body = json_encode($body, JSON_THROW_ON_ERROR);
                $headers[] = 'Content-Type: application/json';
            } elseif ($encoding === 'form') {
                $body = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('External request failed (HTTP ' . $status . ($err ? ', ' . $err : '') . ')');
        }
        return (string)$raw;
    }
}
