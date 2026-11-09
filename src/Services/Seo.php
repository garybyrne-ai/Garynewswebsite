<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;

/**
 * Search visibility: the canonical domain, ownership verification tags, the IndexNow key
 * and the ping that tells Bing, Yandex and the AI crawlers a story changed.
 */
final class Seo
{
    /** Verification meta tags the newsroom can paste keys into. setting => meta name */
    public const VERIFY = [
        'verify_google' => 'google-site-verification',
        'verify_bing' => 'msvalidate.01',
        'verify_yandex' => 'yandex-verification',
        'verify_pinterest' => 'p:domain_verify',
        'verify_facebook' => 'facebook-domain-verification',
    ];

    /** Social profiles published as schema.org sameAs. setting => label */
    public const SOCIAL = [
        'social_facebook' => 'Facebook page',
        'social_x' => 'X (Twitter) profile',
        'social_instagram' => 'Instagram profile',
        'social_youtube' => 'YouTube channel',
        'social_linkedin' => 'LinkedIn page',
        'social_tiktok' => 'TikTok profile',
        'social_bluesky' => 'Bluesky profile',
        'social_threads' => 'Threads profile',
    ];

    /** The IndexNow endpoints. One ping is shared between them. */
    private const INDEXNOW = 'https://api.indexnow.org/indexnow';

    /** @return array<string,string> meta name => content, for the pages' head */
    public static function verificationTags(): array
    {
        $out = [];
        foreach (self::VERIFY as $key => $meta) {
            $v = trim((string)Database::setting($key, ''));
            if ($v !== '') {
                $out[$meta] = $v;
            }
        }
        return $out;
    }

    /** @return list<string> the social profile URLs, for sameAs */
    public static function sameAs(): array
    {
        $out = [];
        foreach (array_keys(self::SOCIAL) as $key) {
            $v = trim((string)Database::setting($key, ''));
            if ($v !== '' && preg_match('~^https?://~i', $v)) {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    /** The IndexNow key for this site, generated once and then kept. */
    public static function indexNowKey(): string
    {
        $key = trim((string)Database::setting('indexnow_key', ''));
        if ($key === '' || !preg_match('/^[a-f0-9]{8,128}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            Database::setSetting('indexnow_key', $key);
        }
        return $key;
    }

    /** Is this request already on the canonical host? */
    public static function canonicalHost(): string
    {
        $base = trim((string)Database::setting('site_url', ''));
        return $base === '' ? '' : (string)(parse_url($base, PHP_URL_HOST) ?: '');
    }

    /**
     * The absolute URL this request should have been made to, or null when it is already right.
     * Only GET and HEAD are redirected: a POST to the staging host must not lose its body.
     */
    public static function canonicalRedirect(string $method, string $host, string $uri): ?string
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }
        if ((string)Database::setting('canonical_redirect', '1') !== '1') {
            return null;
        }
        $want = self::canonicalHost();
        if ($want === '') {
            return null;
        }
        $have = strtolower(preg_replace('/:\d+$/', '', $host) ?? '');
        if ($have === '' || $have === strtolower($want)) {
            return null;
        }
        // Never bounce a local or private address: that is a developer looking at their own copy.
        if ($have === 'localhost' || filter_var($have, FILTER_VALIDATE_IP) || !str_contains($have, '.')) {
            return null;
        }
        $base = rtrim((string)Database::setting('site_url', ''), '/');
        return $base . '/' . ltrim($uri, '/');
    }

    /**
     * Tell IndexNow a handful of URLs changed. Fire-and-forget: search engines are not
     * allowed to hold up a newsroom save, so every failure is swallowed and recorded.
     *
     * @param list<string> $urls absolute URLs on this site
     */
    public static function ping(array $urls): bool
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === [] || !function_exists('curl_init')) {
            return false;
        }
        $host = self::canonicalHost() ?: (string)(parse_url(Config::baseUrl(), PHP_URL_HOST) ?: '');
        if ($host === '' || $host === 'localhost' || !str_contains($host, '.')) {
            return false;
        }
        // Only the server actually answering for that domain may claim its URLs changed: a
        // staging copy or a test run must never ping on the live site's behalf.
        $serving = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($serving === '' || $serving !== strtolower($host)) {
            return false;
        }
        $key = self::indexNowKey();
        $payload = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => rtrim(Config::baseUrl(), '/') . '/' . $key . '.txt',
            'urlList' => array_slice($urls, 0, 100),
        ], JSON_UNESCAPED_SLASHES);
        $ch = curl_init(self::INDEXNOW);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $ok = curl_exec($ch) !== false;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        Database::setSetting('indexnow_last_at', gmdate('Y-m-d\TH:i:s') . '+00:00');
        Database::setSetting('indexnow_last_result', $ok ? (string)$code : mb_substr('error: ' . $err, 0, 200));
        return $ok && $code >= 200 && $code < 300;
    }

    /** Ping for one story, if it is ours and published. */
    public static function pingStory(array $story): void
    {
        if (($story['kind'] ?? '') === 'wire' || ($story['status'] ?? '') !== 'published' || empty($story['slug'])) {
            return;
        }
        self::ping([absolute_url('/story/' . $story['slug']), rtrim(Config::baseUrl(), '/') . '/']);
    }
}
