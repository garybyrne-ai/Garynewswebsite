<?php
declare(strict_types=1);

namespace MeNews\Support;

/**
 * Small, consistent inline SVG icon set (24×24, 1.75px strokes). Replaces the earlier
 * typographic glyphs so that icons read on every device and never depend on a font.
 * Every icon is decorative unless a label is passed, in which case it is announced.
 */
final class Icons
{
    private const PATHS = [
        'pin' => '<path d="M12 21s-6-5.2-6-10.5a6 6 0 0 1 12 0C18 15.8 12 21 12 21z"/><circle cx="12" cy="10.5" r="2.3"/>',
        'signal' => '<path d="M4 18v-5M9 18V9M14 18v-7M19 18V5"/>',
        'map' => '<path d="M3 6.5 9 4l6 2.5L21 4v13.5L15 20l-6-2.5L3 20z"/><path d="M9 4v13.5M15 6.5V20"/>',
        'kids' => '<path d="M4 6h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M8 10h8M8 14h5"/><path d="M9 3v3M15 3v3"/>',
        'star' => '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 16.9l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'report' => '<path d="M5 21V4"/><path d="M5 4h12l-2 4 2 4H5"/>',
        'alert' => '<path d="M12 3 2.5 19.5h19z"/><path d="M12 10v4M12 17.2v.3"/>',
        'home' => '<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'more' => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4.5-6.2"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'external' => '<path d="M7 17 17 7M9 7h8v8"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'candle' => '<path d="M9 10h6v10H9z"/><path d="M12 10V7"/><path d="M12 3c-1.2 1.2-1.2 2.4 0 3.6 1.2-1.2 1.2-2.4 0-3.6z"/><path d="M6 20h12"/>',
        'world' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.8 2.8 2.8 14.2 0 17M12 3.5c-2.8 2.8-2.8 14.2 0 17"/>',
        'trophy' => '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/><path d="M12 14v3M8 20h8M10 17h4v3h-4z"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M9 7.5V5.5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5.5v2M3 12.5h18"/>',
        'culture' => '<path d="M9 18V6l11-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',
        'community' => '<circle cx="12" cy="7" r="3.2"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M3.5 12.5a2.5 2.5 0 1 0 0-.1M20.5 12.5a2.5 2.5 0 1 0 0-.1"/>',
        'traffic' => '<path d="M5 13 6.8 7.5A2 2 0 0 1 8.7 6h6.6a2 2 0 0 1 1.9 1.5L19 13"/><rect x="3.5" y="13" width="17" height="5" rx="1.5"/><path d="M6 18v2M18 18v2M7 15.5h.5M16.5 15.5h.5"/>',
        'council' => '<path d="M3 21h18M5 21V10M19 21V10M9 21v-6h6v6M2.5 10 12 4l9.5 6z"/>',
        'national' => '<path d="M12 4c-1.5 3-4.5 3-6 1 0 4 3 5.5 6 6-3 .5-6 2-6 6 1.5-2 4.5-2 6 1 1.5-3 4.5-3 6-1 0-4-3-5.5-6-6 3-.5 6-2 6-6-1.5 2-4.5 2-6-1z"/><path d="M12 11v9"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
        'camera' => '<path d="M4 8h3.5l1.5-2.5h6L16.5 8H20v11H4z"/><circle cx="12" cy="13" r="3.2"/>',
        'whatsapp' => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8.4 19z"/><path d="M9.5 9.5c0 3 2.2 5 5 5l1-1.5-1.8-1-1 .8a4 4 0 0 1-1.6-1.6l.8-1-1-1.8z"/>',
        'mail' => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'phone' => '<path d="M6.5 3.5h3l1.5 4-2 1.5a10 10 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.5M3.5 12h.5M3.5 18h.5"/>',
        'bell' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'share' => '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="m8.2 10.8 7.6-4.1M8.2 13.2l7.6 4.1"/>',
        'text' => '<path d="M4 7V4h16v3M12 4v16M9 20h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'bookmark' => '<path d="M6 4h12v17l-6-4-6 4z"/>',
        'rss' => '<path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>',
        'megaphone' => '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1z"/><path d="M17 8.5a4 4 0 0 1 0 7M8.5 15l1 5H12"/>',
        'minus' => '<path d="M5 12h14"/>',
        'gps' => '<circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.5"/><path d="M12 2.5V6M12 18v3.5M2.5 12H6M18 12h3.5"/>',
        'shield' => '<path d="M12 3 4.5 6v6c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6.2 6.2l2 2M15.8 15.8l2 2M6.2 17.8l2-2M15.8 8.2l2-2"/>',
        'crown' => '<path d="m4 17 1.5-9 4.5 4 2-6 2 6 4.5-4L20 17z"/><path d="M4.5 20h15"/>',
        'quote' => '<path d="M7 16c-2 0-3-1.3-3-3.2C4 9.8 6.5 7.5 9.5 6.5l.6 1.4C8 8.7 7 10 7 11.3c1.5 0 2.5 1 2.5 2.4A2.3 2.3 0 0 1 7 16zm9 0c-2 0-3-1.3-3-3.2 0-3 2.5-5.3 5.5-6.3l.6 1.4c-2.1.8-3.1 2.1-3.1 3.4 1.5 0 2.5 1 2.5 2.4A2.3 2.3 0 0 1 16 16z"/>',
        'dot' => '<circle cx="12" cy="12" r="4"/>',
        'school' => '<path d="M3 10 12 5l9 5-9 5z"/><path d="M6.5 12v4.5c2 2 9 2 11 0V12M21 10v5"/>',
        'wind' => '<path d="M3 8h11a3 3 0 1 0-3-3M3 12h15a3 3 0 1 1-3 3M3 16h8a2.5 2.5 0 1 1-2.5 2.5"/>',
        'paw' => '<circle cx="7" cy="9" r="1.8"/><circle cx="11" cy="6" r="1.8"/><circle cx="16" cy="7" r="1.8"/><circle cx="19" cy="11.5" r="1.8"/><path d="M13 12c-3 0-6 2.6-6 5.2 0 1.5 1.1 2.3 2.4 2.3 1.2 0 2.2-.7 3.6-.7s2.4.7 3.6.7c1.3 0 2.4-.8 2.4-2.3 0-2.6-3-5.2-6-5.2z"/>',
        'building' => '<rect x="4" y="3.5" width="12" height="17"/><path d="M16 9.5h4v11h-4M7.5 7h2M7.5 11h2M7.5 15h2M11.5 7h2M11.5 11h2M11.5 15h2M8.5 20.5v-3h3v3"/>',
        'bolt' => '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.2 4.2 0 0 1 12 7.6 4.2 4.2 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
        'flame' => '<path d="M12 21c-3.9 0-6.5-2.6-6.5-6 0-3 2.2-5 3.3-7.5 1 1.2 1.7 2.3 1.7 3.5 1.2-1.5 2-4 2-7 3 2.3 6 5.8 6 11 0 3.4-2.6 6-6.5 6z"/>',
        'magnifier' => '<circle cx="10.5" cy="10.5" r="5.5"/><path d="m20 20-5.6-5.6M8 10.5h5"/>',
        'audio' => '<path d="M4 10v4h3l4 3.5v-11L7 10z"/><path d="M15 9a4 4 0 0 1 0 6M17.5 6.5a7.5 7.5 0 0 1 0 11"/>',
        'edit' => '<path d="m4 20 4-1L19.5 7.5l-3-3L5 16z"/><path d="m14 6 3 3"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>',
        'timeline' => '<path d="M4 12h16"/><circle cx="7" cy="12" r="2"/><circle cx="17" cy="12" r="2"/><path d="M7 6v4M17 14v4"/>',
    ];

    /** Names understood by icon(). */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /**
     * Render an icon. $label makes it accessible (role="img" + title); otherwise aria-hidden.
     */
    public static function svg(string $name, string $class = '', string $label = ''): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['dot'];
        $cls = trim('icon icon--' . $name . ' ' . $class);
        $a11y = $label !== ''
            ? ' role="img" aria-label="' . e($label) . '"'
            : ' aria-hidden="true" focusable="false"';
        return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"' . $a11y . '>'
            . ($label !== '' ? '<title>' . e($label) . '</title>' : '') . $paths . '</svg>';
    }
}
