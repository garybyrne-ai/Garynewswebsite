<?php
declare(strict_types=1);

/** HTML-escape a value for safe output inside templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current UTC timestamp in the canonical ISO-8601 format stored in the database. */
function now(): string
{
    return gmdate('Y-m-d\TH:i:s') . '+00:00';
}

/** Random 32-character hexadecimal identifier. */
function uuid(): string
{
    return bin2hex(random_bytes(16));
}

/** Build an absolute URL for a path using PUBLIC_BASE_URL. */
function absolute_url(string $path = '/'): string
{
    return rtrim(MeNews\Config::baseUrl(), '/') . '/' . ltrim($path, '/');
}

/** Human-friendly relative time ("4 min ago", "Yesterday"). */
function time_ago(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . ' min ago';
    }
    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return $h . ' hr' . ($h > 1 ? 's' : '') . ' ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }
    if ($diff < 86400 * 7) {
        return intdiv($diff, 86400) . ' days ago';
    }
    return date_irish($iso, 'j M Y');
}

/** Format an ISO timestamp in Irish local time. */
function date_irish(?string $iso, string $format = 'D j M Y, H:i'): string
{
    if (!$iso) {
        return '';
    }
    try {
        $zone = new DateTimeZone(MeNews\Config::get('APP_TIMEZONE', 'Europe/Dublin'));
        // Naive timestamps (no offset), e.g. funeral or event times typed by a person, are Irish local time.
        $dt = new DateTimeImmutable($iso, $zone);
        return $dt->setTimezone($zone)->format($format);
    } catch (Throwable) {
        return '';
    }
}

/** URL-safe slug from free text (keeps Irish fadas readable by transliterating them). */
function slugify(string $text, int $max = 80): string
{
    $text = mb_strtolower(trim($text));
    $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'ñ' => 'n', 'ç' => 'c', '’' => '', "'" => ''];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if (mb_strlen($text) > $max) {
        $text = rtrim(mb_substr($text, 0, $max), '-');
    }
    return $text ?: 'story';
}

/** Truncate text on a word boundary. */
function excerpt(?string $text, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space ? mb_substr($cut, 0, $space) : $cut, ' ,.;:') . '…';
}

/** Compact number formatting (1.2k, 3.4M). */
function compact_number(int|float $n): string
{
    if ($n >= 1_000_000) {
        return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
    }
    return (string)$n;
}

/** Inline SVG icon (see Support\Icons). Decorative unless $label is given. */
function icon(string $name, string $class = '', string $label = ''): string
{
    return MeNews\Support\Icons::svg($name, $class, $label);
}

/**
 * Reader-facing view count: never a suspiciously exact number. Below the threshold nothing
 * is shown at all (no number beats a small number); above it the count is bucketed ("900+").
 */
function views_label(int $views, int $threshold = 100): string
{
    if ($views < $threshold) {
        return '';
    }
    if ($views >= 10000) {
        return compact_number((int)floor($views / 1000) * 1000) . '+';
    }
    if ($views >= 1000) {
        return rtrim(rtrim(number_format(floor($views / 100) / 10, 1), '0'), '.') . 'k+';
    }
    return (string)((int)floor($views / 100) * 100) . '+';
}

/** Possessive form for county names ("Dublin's", "Laois'"). */
function possessive(string $name): string
{
    return $name . (str_ends_with($name, 's') ? '’' : '’s');
}

/** Translate a site-chrome string into the visitor's language (English when unknown). */
function t(string $text): string
{
    return \MeNews\Support\Lang::t($text);
}
