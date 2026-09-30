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

    /** Stop words short/common enough that matching on them alone would return junk. */
    private const STOPWORDS = ['this', 'that', 'with', 'from', 'have', 'will', 'says', 'over', 'after', 'india', 'news', 'their', 'about', 'into'];

    /**
     * Trending searches for a state (or nationally if none/unmapped), each with the first
     * linked news story where Google supplied one, and an `internal_url` — the click target we
     * actually send readers to. It always stays on this site: our own story on the topic when
     * we have one, otherwise our own search results for the query, never straight to the
     * external outlet. The external story is still named (for the reader's own context) and
     * linked separately, small, for anyone who wants to go there on purpose afterwards.
     * @return array<int, array{query:string,traffic:string,news_title:?string,news_url:?string,news_source:?string,picture:?string,internal_url:string}>
     */
    public static function forState(?string $state, int $limit = 8): array
    {
        $code = $state ? (self::STATE_CODES[$state] ?? null) : null;
        $geo = $code ? 'IN-' . $code : 'IN';
        $rows = self::fetch($geo, $limit);
        foreach ($rows as &$row) {
            $row['internal_url'] = self::internalLink($row['query'], $row['news_title']);
        }
        return $rows;
    }

    /** Our own coverage of a trend if we have any, else our own search page for it — never a third-party URL. */
    private static function internalLink(string $query, ?string $newsTitle): string
    {
        $hit = \MeNews\Stories::feed(['q' => $query], 1);
        if ($hit) {
            return $hit[0]['url'];
        }
        foreach (array_filter([$newsTitle, $query]) as $text) {
            $words = array_filter(preg_split('/\s+/u', mb_strtolower((string)$text)) ?: [], static fn($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOPWORDS, true));
            foreach (array_slice($words, 0, 3) as $word) {
                $hit = \MeNews\Stories::feed(['q' => $word], 1);
                if ($hit) {
                    return $hit[0]['url'];
                }
            }
        }
        return '/search?q=' . rawurlencode($query);
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
