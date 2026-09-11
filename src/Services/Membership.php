<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;

/** ME+ pricing (editable in the newsroom) and what the plan unlocks. */
final class Membership
{
    public static function priceCents(): int
    {
        return max(100, (int)(Database::setting('plus_price_cents', '399') ?? 399));
    }

    public static function annualCents(): int
    {
        return max(500, (int)(Database::setting('plus_annual_cents', '3900') ?? 3900));
    }

    public static function priceLabel(): string
    {
        return '€' . number_format(self::priceCents() / 100, 2) . '/month';
    }

    public static function annualLabel(): string
    {
        return '€' . number_format(self::annualCents() / 100, 0) . '/year';
    }

    public static function followLimit(?array $user): int
    {
        return $user && ($user['plan'] ?? '') === 'ME+' ? 10 : 1;
    }

    public static function isPlus(?array $user): bool
    {
        return $user !== null && ($user['plan'] ?? '') === 'ME+';
    }

    /** Benefits shown on the plan page and sidebar. */
    public static function benefits(): array
    {
        return [
            'Ad-free reading on every page',
            'Death notice and school closure alerts for up to ten towns or counties',
            'The 7am morning email for every area you follow',
            'The full archive and every county poll breakdown',
            'A members-only monthly county newsletter',
            'A member badge on your comments and reports',
            'Directly funds council and court reporting in your county',
        ];
    }
}
