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

    /** ME+ members read ad-free; staff always see adverts so they can check what is running. */
    public static function adFree(?array $user): bool
    {
        return self::isPlus($user) && !in_array($user['role'] ?? '', ['editor', 'admin'], true);
    }

    /** Staff read everything; members read the archive; everyone else gets the last N days. */
    public static function fullArchive(?array $user): bool
    {
        return self::isPlus($user) || ($user !== null && in_array($user['role'] ?? '', ['editor', 'admin'], true));
    }

    public static function archiveDays(): int
    {
        return max(1, (int)(Database::setting('archive_days', '14') ?? 14));
    }

    /** ISO timestamp before which stories are members-only, or null when the reader has the full archive. */
    public static function archiveCutoff(?array $user): ?string
    {
        return self::fullArchive($user) ? null : gmdate('Y-m-d\TH:i:s', time() - self::archiveDays() * 86400) . '+00:00';
    }

    public static function isArchived(array $story, ?array $user): bool
    {
        $cutoff = self::archiveCutoff($user);
        return $cutoff !== null && (string)($story['published_at'] ?: $story['created_at']) < $cutoff;
    }

    /** How many counties an email address may receive alerts for. */
    public static function alertLimit(?array $user, string $email = ''): int
    {
        if ($user === null && $email !== '') {
            $user = Database::one('SELECT plan, role FROM users WHERE email=?', [mb_strtolower($email)]);
        }
        return self::isPlus($user) ? 10 : 1;
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
