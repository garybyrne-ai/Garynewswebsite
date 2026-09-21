<?php
declare(strict_types=1);

namespace MeNews\Support;

/**
 * Site language. The chrome (menus, buttons, footer) is translated natively from
 * config/lang/*.json; article text is translated in the browser by Google Translate for the
 * non-English languages, so every story is readable without a translation budget.
 */
final class Lang
{
    public const DEFAULT = 'en-GB';
    public const COOKIE = 'me_lang';

    /** code => [label in English, native name, Google Translate code (null = no translation), flag] */
    public const LANGS = [
        'en-GB' => ['English (UK)', 'English (UK)', null, '🇬🇧'],
        'en-US' => ['English (US)', 'English (US)', null, '🇺🇸'],
        'ga' => ['Irish', 'Gaeilge', 'ga', '🇮🇪'],
        'pl' => ['Polish', 'Polski', 'pl', '🇵🇱'],
        'de' => ['German', 'Deutsch', 'de', '🇩🇪'],
        'uk' => ['Ukrainian', 'Українська', 'uk', '🇺🇦'],
        'ru' => ['Russian', 'Русский', 'ru', '🇷🇺'],
    ];

    private static ?string $current = null;
    private static array $dicts = [];

    public static function valid(string $code): bool
    {
        return isset(self::LANGS[$code]);
    }

    public static function current(): string
    {
        if (self::$current === null) {
            $c = (string)($_COOKIE[self::COOKIE] ?? '');
            self::$current = self::valid($c) ? $c : self::DEFAULT;
        }
        return self::$current;
    }

    public static function set(string $code): void
    {
        self::$current = self::valid($code) ? $code : self::DEFAULT;
    }

    /** BCP 47 tag for <html lang>. */
    public static function htmlLang(): string
    {
        return self::current();
    }

    /** Google Translate target for the current language, or null for English. */
    public static function googleCode(): ?string
    {
        return self::LANGS[self::current()][2];
    }

    public static function googleCodes(): string
    {
        return implode(',', array_values(array_filter(array_map(static fn($l) => $l[2], self::LANGS))));
    }

    public static function dict(?string $code = null): array
    {
        $code ??= self::current();
        if (!isset(self::$dicts[$code])) {
            $file = ME_ROOT . '/config/lang/' . $code . '.json';
            self::$dicts[$code] = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        }
        return self::$dicts[$code];
    }

    /** Translate a chrome string; unknown strings fall back to English. */
    public static function t(string $text): string
    {
        return self::dict()[$text] ?? $text;
    }

    /** Name of the current language as its speakers write it. */
    public static function nativeName(?string $code = null): string
    {
        return self::LANGS[$code ?? self::current()][1];
    }
}
