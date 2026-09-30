<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use SimpleXMLElement;
use Throwable;

/**
 * "What India is searching for" — Google's free, keyless real-time trending-searches feed,
 * scoped per state where Google publishes it (ISO 3166-2 subdivision codes). There is no public
 * API for what people search on Facebook, Instagram or X — X's was paywalled in 2023 and Meta
 * never published one — so this is the closest honest equivalent: real regional search demand,
 * each trend already carrying a linked news story (headline, source, thumbnail) rather than a
 * bare keyword, which is what makes it worth a sidebar panel rather than a flat word list.
 */
final class Trends
{
    private const NS = 'https://trends.google.com/trending/rss';

    /** State name => ISO 3166-2:IN subdivision code Google Trends recognises. */
    private const STATE_CODES = [
        'Andhra Pradesh' => 'AP', 'Arunachal Pradesh' => 'AR', 'Assam' => 'AS', 'Bihar' => 'BR',
        'Chhattisgarh' => 'CT', 'Goa' => 'GA', 'Gujarat' => 'GJ', 'Haryana' => 'HR',
        'Himachal Pradesh' => 'HP', 'Jharkhand' => 'JH', 'Karnataka' => 'KA', 'Kerala' => 'KL',
        'Madhya Pradesh' => 'MP', 'Maharashtra' => 'MH', 'Manipur' => 'MN', 'Meghalaya' => 'ML',
        'Mizoram' => 'MZ', 'Nagaland' => 'NL', 'Odisha' => 'OR', 'Punjab' => 'PB',
        'Rajasthan' => 'RJ', 'Sikkim' => 'SK', 'Tamil Nadu' => 'TN', 'Telangana' => 'TG',
        'Tripura' => 'TR', 'Uttar Pradesh' => 'UP', 'Uttarakhand' => 'UT', 'West Bengal' => 'WB',
        'Andaman and Nicobar Islands' => 'AN', 'Chandigarh' => 'CH', 'Delhi' => 'DL',
        'Jammu and Kashmir' => 'JK', 'Ladakh' => 'LA', 'Lakshadweep' => 'LD', 'Puducherry' => 'PY',
    ];

    /**
     * Trending searches for a state (or nationally if none/unmapped), each with the first
     * linked news story where Google supplied one.
     * @return array<int, array{query:string,traffic:string,news_title:?string,news_url:?string,news_source:?string,picture:?string}>
     */
    public static function forState(?string $state, int $limit = 8): array
    {
        $code = $state ? (self::STATE_CODES[$state] ?? null) : null;
        $geo = $code ? 'IN-' . $code : 'IN';
        return self::fetch($geo, $limit);
    }

    private static function fetch(string $geo, int $limit): array
    {
        $file = Config::storage() . '/cache/trends-' . strtolower(str_replace('-', '_', $geo)) . '.json';
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 3600) {
            return array_slice($cached['rows'], 0, $limit);
        }
        try {
            $raw = Remote::get('https://trends.google.com/trending/rss?geo=' . rawurlencode($geo), ['Accept: application/rss+xml'], 8);
            $xml = new SimpleXMLElement($raw);
            $rows = [];
            foreach ($xml->channel->item as $item) {
                $ht = $item->children(self::NS);
                $news = $ht->news_item ? $ht->news_item[0] : null;
                $newsNs = $news ? $news->children(self::NS) : null;
                $rows[] = [
                    'query' => trim((string)$item->title),
                    'traffic' => trim((string)$ht->approx_traffic),
                    'news_title' => $newsNs ? trim((string)$newsNs->news_item_title) : null,
                    'news_url' => $newsNs ? trim((string)$newsNs->news_item_url) : null,
                    'news_source' => $newsNs ? trim((string)$newsNs->news_item_source) : null,
                    'picture' => trim((string)$ht->picture) ?: null,
                ];
            }
            file_put_contents($file, json_encode(['updated' => now(), 'rows' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
            return array_slice($rows, 0, $limit);
        } catch (Throwable $e) {
            error_log('Trends (' . $geo . '): ' . $e->getMessage());
            return is_array($cached) ? array_slice($cached['rows'], 0, $limit) : [];
        }
    }
}
