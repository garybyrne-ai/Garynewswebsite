<?php
declare(strict_types=1);

namespace MeNews\Support;

/**
 * Which human language a wire story's own text is in — a different axis from Lang (the site
 * chrome's UI language a *reader* has chosen). A Hindi-language story shows to every reader
 * regardless of their chosen UI language; this only drives the language chip/badge and the
 * /language/{code} browse page.
 */
final class Languages
{
    /** ISO 639-1 code => [English label, native name] */
    public const NAMES = [
        'en' => ['English', 'English'],
        'hi' => ['Hindi', 'हिन्दी'],
        'bn' => ['Bengali', 'বাংলা'],
        'ta' => ['Tamil', 'தமிழ்'],
        'te' => ['Telugu', 'తెలుగు'],
        'kn' => ['Kannada', 'ಕನ್ನಡ'],
        'ml' => ['Malayalam', 'മലയാളം'],
        'mr' => ['Marathi', 'मराठी'],
        'gu' => ['Gujarati', 'ગુજરાતી'],
        'pa' => ['Punjabi', 'ਪੰਜਾਬੀ'],
        'or' => ['Odia', 'ଓଡ଼ିଆ'],
        'as' => ['Assamese', 'অসমীয়া'],
        'ur' => ['Urdu', 'اردو'],
    ];

    public static function native(string $code): string
    {
        return self::NAMES[$code][1] ?? $code;
    }

    public static function label(string $code): string
    {
        return self::NAMES[$code][0] ?? $code;
    }

    public static function valid(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }
}
