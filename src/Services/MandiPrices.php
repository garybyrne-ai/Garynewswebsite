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
            self::recordHistory($state, $rows);
            return $rows;
        } catch (Throwable $e) {
            error_log('Mandi prices (state ' . $state . '): ' . $e->getMessage());
            return is_array($cached) ? $cached['rows'] : [];
        }
    }

    /** One modal-price reading per commodity per state per day — skip commodities already logged today. */
    private static function recordHistory(string $state, array $rows): void
    {
        $today = substr(now(), 0, 10);
        foreach ($rows as $row) {
            if ($row['commodity'] === '' || $row['modal'] === '') {
                continue;
            }
            if (Database::value('SELECT 1 FROM mandi_history WHERE county=? AND commodity=? AND substr(recorded_at,1,10)=?', [$state, $row['commodity'], $today])) {
                continue;
            }
            Database::insert('mandi_history', ['county' => $state, 'commodity' => $row['commodity'], 'modal' => $row['modal'], 'recorded_at' => now()]);
        }
    }

    /** Daily modal-price readings for one commodity over the last $days, oldest first. */
    public static function history(string $state, string $commodity, int $days = 30): array
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - $days * 86400) . '+00:00';
        return Database::all('SELECT modal,recorded_at FROM mandi_history WHERE county=? AND commodity=? AND recorded_at>=? ORDER BY recorded_at ASC', [$state, $commodity, $since]);
    }

    /** Commodities with at least 2 days of history for a state, for a "chart this" picker. */
    public static function chartableCommodities(string $state): array
    {
        return array_column(Database::all('SELECT commodity, COUNT(DISTINCT substr(recorded_at,1,10)) AS n FROM mandi_history WHERE county=? GROUP BY commodity HAVING n>=2 ORDER BY commodity', [$state]), 'commodity');
    }
}
