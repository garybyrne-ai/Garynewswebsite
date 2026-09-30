<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use Throwable;

/**
 * Mandi (wholesale market) commodity prices for a state, from data.gov.in's daily market-price
 * dataset (published by Agmarknet). Free, but needs a personal API key (instant, no cost, at
 * https://data.gov.in/user/register — the key comes from your data.gov.in account, not a
 * separate Agmarknet signup). Inactive — returns an empty list, no error — until an admin adds
 * one under Settings → Live data. Cached per state for an hour, since mandi prices update daily.
 *
 * The resource id below is data.gov.in's long-standing "Variety-wise Daily Market Prices Data of
 * Commodity" dataset; data.gov.in occasionally reissues resource ids when a dataset is
 * republished, so if this starts returning nothing, check the dataset's current id at
 * https://www.data.gov.in/resource/variety-wise-daily-market-prices-data-commodity and update
 * the constant below.
 */
final class MandiPrices
{
    private const RESOURCE_ID = '9ef84268-d588-465a-a308-a864a43d0070';

    public static function configured(): bool
    {
        return Database::setting('agmarknet_api_key', '') !== '';
    }

    /** @return array<int, array{commodity:string,market:string,min:string,max:string,modal:string,date:string}> */
    public static function forState(string $state): array
    {
        $key = (string)Database::setting('agmarknet_api_key', '');
        if ($key === '') {
            return [];
        }
        $file = Config::storage() . '/cache/mandi-' . slugify($state) . '.json';
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && isset($cached['updated']) && time() - (int)strtotime($cached['updated']) < 3600) {
            return $cached['rows'];
        }
        try {
            $url = 'https://api.data.gov.in/resource/' . self::RESOURCE_ID . '?' . http_build_query([
                'api-key' => $key, 'format' => 'json', 'limit' => 12, 'filters[state]' => $state,
            ]);
            $row = json_decode(Remote::get($url, ['Accept: application/json'], 10), true, 512, JSON_THROW_ON_ERROR);
            $rows = [];
            foreach ((array)($row['records'] ?? []) as $r) {
                $rows[] = [
                    'commodity' => (string)($r['commodity'] ?? ''), 'market' => (string)($r['market'] ?? ''),
                    'min' => (string)($r['min_price'] ?? ''), 'max' => (string)($r['max_price'] ?? ''),
                    'modal' => (string)($r['modal_price'] ?? ''), 'date' => (string)($r['arrival_date'] ?? ''),
                ];
            }
            file_put_contents($file, json_encode(['updated' => now(), 'rows' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $rows;
        } catch (Throwable $e) {
            error_log('Mandi prices (state ' . $state . '): ' . $e->getMessage());
            return is_array($cached) ? $cached['rows'] : [];
        }
    }
}
