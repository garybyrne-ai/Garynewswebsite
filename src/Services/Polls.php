<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Support\Daily;

/**
 * The weekly county poll. One question a week, drawn from config/polls.json (editors can
 * replace it in the newsroom), answered anonymously, and published as a county-by-county
 * breakdown, which is itself a story other outlets pick up.
 */
final class Polls
{
    public static function week(): string
    {
        return Daily::today()->format('o-\WW');
    }

    /** The current week's poll, created from the bank on first use. */
    public static function current(?string $county = null): ?array
    {
        $week = self::week();
        $poll = Database::one('SELECT * FROM polls WHERE week=?', [$week]);
        if (!$poll) {
            $bank = json_decode((string)file_get_contents(ME_ROOT . '/config/polls.json'), true) ?: [];
            if (!$bank) {
                return null;
            }
            $q = $bank[Daily::rng("poll-" . $week)->int(0, count($bank) - 1)];
            $closes = Daily::today()->modify('next monday')->setTime(7, 0);
            try {
                Database::insert('polls', ['id' => uuid(), 'week' => $week, 'question' => $q['question'], 'options_json' => json_encode($q['options'], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'closes_at' => $closes->format('c'), 'status' => 'open']);
            } catch (\Throwable) {
                // raced with another request
            }
            $poll = Database::one('SELECT * FROM polls WHERE week=?', [$week]);
        }
        return $poll ? self::present($poll, $county) : null;
    }

    public static function byId(string $id, ?string $county = null): ?array
    {
        $poll = Database::one('SELECT * FROM polls WHERE id=?', [$id]);
        return $poll ? self::present($poll, $county) : null;
    }

    public static function present(array $poll, ?string $county = null): array
    {
        $poll['options'] = json_decode((string)$poll['options_json'], true) ?: [];
        $poll['results'] = self::results($poll['id']);
        $poll['county_results'] = $county ? self::results($poll['id'], $county) : null;
        $poll['breakdown'] = self::breakdown($poll['id'], count($poll['options']));
        $poll['mine'] = self::myVote($poll['id']);
        $poll['closed'] = $poll['status'] !== 'open' || strtotime($poll['closes_at']) < time();
        $poll['url'] = '/poll';
        unset($poll['options_json']);
        return $poll;
    }

    public static function voterKey(): string
    {
        return Signal::voterKey(\MeNews\Auth::user());
    }

    public static function myVote(string $pollId): ?int
    {
        $v = Database::value('SELECT option_idx FROM poll_votes WHERE poll_id=? AND voter_key=?', [$pollId, self::voterKey()]);
        return $v === false || $v === null ? null : (int)$v;
    }

    public static function vote(string $pollId, int $option, ?string $county, string $ip): array
    {
        $poll = Database::one('SELECT * FROM polls WHERE id=?', [$pollId]);
        if (!$poll) {
            throw new HttpException(404, 'Poll not found');
        }
        $options = json_decode((string)$poll['options_json'], true) ?: [];
        if ($option < 0 || $option >= count($options)) {
            throw new HttpException(400, 'Pick an option');
        }
        if ($poll['status'] !== 'open' || strtotime($poll['closes_at']) < time()) {
            throw new HttpException(409, 'This poll has closed');
        }
        RateLimiter::hit($ip . '|poll', 30, 3600, 'Too many votes from this connection.');
        Database::query('INSERT INTO poll_votes(poll_id,voter_key,option_idx,county,created_at) VALUES(?,?,?,?,?) ON CONFLICT(poll_id,voter_key) DO UPDATE SET option_idx=excluded.option_idx,county=excluded.county', [$pollId, self::voterKey(), $option, $county, now()]);
        return self::present($poll, $county);
    }

    /** @return array{total:int,counts:int[],pct:int[]} */
    public static function results(string $pollId, ?string $county = null): array
    {
        $rows = Database::all('SELECT option_idx, COUNT(*) AS n FROM poll_votes WHERE poll_id=?' . ($county ? ' AND county=?' : '') . ' GROUP BY option_idx', $county ? [$pollId, $county] : [$pollId]);
        $counts = [];
        $total = 0;
        foreach ($rows as $r) {
            $counts[(int)$r['option_idx']] = (int)$r['n'];
            $total += (int)$r['n'];
        }
        $pct = [];
        foreach ($counts as $i => $n) {
            $pct[$i] = $total ? (int)round($n / $total * 100) : 0;
        }
        return ['total' => $total, 'counts' => $counts, 'pct' => $pct];
    }

    /** County-by-county leading option (counties with at least 3 votes). */
    public static function breakdown(string $pollId, int $optionCount): array
    {
        $rows = Database::all("SELECT county, option_idx, COUNT(*) AS n FROM poll_votes WHERE poll_id=? AND county IS NOT NULL AND county<>'' GROUP BY county, option_idx", [$pollId]);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['county']]['total'] = ($by[$r['county']]['total'] ?? 0) + (int)$r['n'];
            $by[$r['county']]['counts'][(int)$r['option_idx']] = (int)$r['n'];
        }
        $out = [];
        foreach ($by as $county => $d) {
            if ($d['total'] < 3) {
                continue;
            }
            arsort($d['counts']);
            $lead = array_key_first($d['counts']);
            $out[] = ['county' => $county, 'total' => $d['total'], 'lead' => $lead, 'lead_pct' => (int)round($d['counts'][$lead] / $d['total'] * 100)];
        }
        usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }
}
