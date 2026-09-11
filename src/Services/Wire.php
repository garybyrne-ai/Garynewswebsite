<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;

/**
 * The wire content position, chosen in the newsroom:
 *   clustered - one card per story with "N outlets covering this" and ME's framing (default)
 *   full      - every outlet's headline as its own card
 *   links     - headline + source + time only; no summaries, no publisher images
 */
final class Wire
{
    private static ?string $mode = null;
    private static ?bool $images = null;

    public static function mode(): string
    {
        if (self::$mode === null) {
            $m = Database::installed() ? (string)Database::setting('wire_mode', 'clustered') : 'clustered';
            self::$mode = in_array($m, ['clustered', 'full', 'links'], true) ? $m : 'clustered';
        }
        return self::$mode;
    }

    public static function collapsed(): bool
    {
        return self::mode() !== 'full';
    }

    /** Whether publisher images are shown on wire cards and pages. */
    public static function images(): bool
    {
        if (self::$images === null) {
            self::$images = self::mode() !== 'links' && (!Database::installed() || Database::setting('wire_images', '1') !== '0');
        }
        return self::$images;
    }

    public static function summaries(): bool
    {
        return self::mode() !== 'links';
    }
}
