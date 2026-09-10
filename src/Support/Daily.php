<?php
declare(strict_types=1);

namespace MeNews\Support;

use DateTimeImmutable;
use DateTimeZone;
use MeNews\Config;

/** Everything that makes a given Irish calendar day unique: edition number, seed, hue, word of the day. */
final class Daily
{
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(Config::get('APP_TIMEZONE', 'Europe/Dublin'));
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** Y-m-d for today in Irish time. */
    public static function date(): string
    {
        return self::today()->format('Y-m-d');
    }

    /** Validate a Y-m-d string, returning it or null. */
    public static function validDate(?string $d): ?string
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d, self::zone());
        return $dt && $dt->format('Y-m-d') === $d ? $d : null;
    }

    /** Sequential edition number counting from the first ME News 4.0 edition. */
    public static function edition(?string $date = null): int
    {
        $d = new DateTimeImmutable($date ?? self::date(), self::zone());
        $start = new DateTimeImmutable('2026-09-01', self::zone());
        return max(1, (int)$start->diff($d)->format('%r%a') + 1);
    }

    public static function seed(string $salt = '', ?string $date = null): string
    {
        return ($date ?? self::date()) . '|' . $salt;
    }

    public static function rng(string $salt = '', ?string $date = null): Rng
    {
        return new Rng(self::seed($salt, $date));
    }

    /** Small daily hue rotation for the aurora background (degrees, -28…+28). */
    public static function hueShift(): int
    {
        return (int)round(sin((int)self::today()->format('z') / 9.0) * 28);
    }

    public static function longDate(?string $date = null): string
    {
        return (new DateTimeImmutable($date ?? self::date(), self::zone()))->format('l j F Y');
    }

    /** Focal an lae — the Irish word of the day. */
    public static function focal(?string $date = null): array
    {
        static $bank;
        $bank ??= json_decode((string)file_get_contents(ME_ROOT . '/config/kids/focal.json'), true)['words'] ?? [];
        if (!$bank) {
            return ['irish' => 'fáilte', 'english' => 'welcome', 'say' => 'FAWL-cha', 'example' => 'Céad míle fáilte!'];
        }
        $d = new DateTimeImmutable($date ?? self::date(), self::zone());
        return $bank[(int)$d->format('z') % count($bank)];
    }

    /** Greeting that changes with the hour. */
    public static function greeting(): string
    {
        $h = (int)self::today()->format('G');
        return $h < 12 ? 'Maidin mhaith' : ($h < 18 ? 'Tráthnóna maith' : 'Oíche mhaith');
    }
}
