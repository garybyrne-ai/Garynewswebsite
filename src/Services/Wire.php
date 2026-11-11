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

    /**
     * How curated wire headlines are offered to search engines.
     *
     *  off        — the default and the safe setting. The page says noindex and canonicalises
     *               to the publisher: their article is the article, and we are a signpost.
     *  commentary — a wire page is indexed only once the desk has written at least
     *               COMMENTARY_WORDS of its own on it. That is ME's reporting, so it is ours
     *               to index, and it carries a citation back to the source.
     *  all        — every wire page is indexed and canonicalises to itself. Google's spam
     *               policy treats a page whose only content is another publisher's headline
     *               and summary as scraped content, and it judges that across the whole site.
     */
    public const INDEX_MODES = ['off', 'commentary', 'all'];

    /** Words of original desk commentary a wire page needs before it is worth indexing. */
    public const COMMENTARY_WORDS = 60;

    public static function indexMode(): string
    {
        $mode = (string)(Database::setting('wire_indexing', 'off') ?? 'off');
        return in_array($mode, self::INDEX_MODES, true) ? $mode : 'off';
    }

    /** How much original reporting the desk has added to this headline. */
    public static function commentaryWords(array $story): int
    {
        $take = trim((string)($story['desk_take'] ?? ''));
        return $take === '' ? 0 : count(preg_split('/\s+/u', $take, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Should this curated headline be indexed in its own right? When it should, the page
     * canonicalises to itself; when it should not, it stays a signpost to the publisher.
     */
    public static function indexable(array $story): bool
    {
        if (($story['kind'] ?? '') !== 'wire') {
            return true;
        }
        return match (self::indexMode()) {
            'all' => true,
            'commentary' => self::commentaryWords($story) >= self::COMMENTARY_WORDS,
            default => false,
        };
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
