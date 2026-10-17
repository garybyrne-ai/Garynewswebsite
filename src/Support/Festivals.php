<?php
declare(strict_types=1);

namespace MeNews\Support;

use DateTimeImmutable;

/**
 * National holidays and festivals, with a next-occurrence countdown for the sidebar and state
 * pages. Fixed-date entries (Republic Day, Christmas…) and Easter/Good Friday (computed with
 * the standard Gauss algorithm) recur exactly every year, no maintenance needed. Lunisolar and
 * Hijri festivals (Diwali, Holi, Eid…) use a small dated table in config/festivals.json — see
 * its _note for why those need refreshing for years beyond what it lists.
 */
final class Festivals
{
    private static ?array $data = null;

    private static function data(): array
    {
        return self::$data ??= json_decode((string)file_get_contents(ME_ROOT . '/config/festivals.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Gauss's algorithm for the date of Easter Sunday (Gregorian calendar). */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** The next future date (today counts) for one config/festivals.json entry, or null if unknown. */
    private static function nextDate(array $entry, DateTimeImmutable $today): ?DateTimeImmutable
    {
        return match ($entry['kind'] ?? '') {
            'fixed' => self::nextAnnual($today, (int)$entry['month'], (int)$entry['day']),
            'easter' => self::nextFromTable(array_map(
                static fn(int $y) => self::easterSunday($y)->modify(($entry['offset_days'] ?? 0) . ' days'),
                [(int)$today->format('Y'), (int)$today->format('Y') + 1]
            ), $today),
            'table' => self::nextFromDatedStrings((array)($entry['dates'] ?? []), $today),
            default => null,
        };
    }

    private static function nextAnnual(DateTimeImmutable $today, int $month, int $day): DateTimeImmutable
    {
        $year = (int)$today->format('Y');
        $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        return $candidate >= $today->setTime(0, 0) ? $candidate : $candidate->modify('+1 year');
    }

    /** @param array<string,string> $dates "YYYY" => "MM-DD" */
    private static function nextFromDatedStrings(array $dates, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $parsed = [];
        foreach ($dates as $year => $md) {
            $parsed[] = new DateTimeImmutable($year . '-' . $md);
        }
        return self::nextFromTable($parsed, $today);
    }

    /** @param DateTimeImmutable[] $dates */
    private static function nextFromTable(array $dates, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $today = $today->setTime(0, 0);
        $future = array_filter($dates, static fn(DateTimeImmutable $d) => $d >= $today);
        if (!$future) {
            return null;
        }
        usort($future, static fn($a, $b) => $a <=> $b);
        return $future[0];
    }

    /** @return array{key:string,name:string,date:string,in_days:int} */
    private static function present(string $key, string $name, DateTimeImmutable $date, DateTimeImmutable $today): array
    {
        return [
            'key' => $key, 'name' => $name, 'date' => $date->format('Y-m-d'),
            'in_days' => (int)$today->setTime(0, 0)->diff($date)->format('%r%a'),
        ];
    }

    /** Upcoming national holidays/festivals, soonest first. */
    public static function upcomingNational(int $limit = 6): array
    {
        $today = new DateTimeImmutable('today');
        $out = [];
        foreach (self::data()['national'] as $entry) {
            $date = self::nextDate($entry, $today);
            if ($date) {
                $out[] = self::present($entry['key'], $entry['name'], $date, $today);
            }
        }
        usort($out, static fn($a, $b) => $a['in_days'] <=> $b['in_days']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Upcoming festivals for one state: its signature festival from config/states.json always
     * takes the first slot (even when it is over a year off — it is the point of the widget, so
     * sorting it away by proximity would defeat the purpose), dated when we have it in
     * byFestivalName; the rest of the slots are the soonest national holidays, deduplicated.
     */
    public static function upcomingForState(string $state, int $limit = 4): array
    {
        $today = new DateTimeImmutable('today');
        $signature = null;
        $profile = StateProfile::get($state);
        if ($profile && !empty($profile['festival'])) {
            $entry = self::data()['byFestivalName'][$profile['festival']] ?? null;
            if ($entry && ($entry['kind'] ?? '') !== 'periodic') {
                $date = self::nextDate($entry, $today);
                if ($date) {
                    $signature = self::present(slugify($profile['festival']), $profile['festival'], $date, $today);
                }
            } elseif (!$entry) {
                // No dated entry for this one — show it without a false-precision date.
                $signature = ['key' => slugify($profile['festival']), 'name' => $profile['festival'], 'date' => null, 'in_days' => null];
            }
        }
        $out = $signature ? [$signature] : [];
        $seen = array_column($out, 'name');
        foreach (self::upcomingNational($limit) as $n) {
            if (count($out) >= $limit) {
                break;
            }
            if (!in_array($n['name'], $seen, true)) {
                $out[] = $n;
                $seen[] = $n['name'];
            }
        }
        return $out;
    }
}
