<?php
declare(strict_types=1);

namespace MeNews;

use MeNews\Support\Categories;

/** Small HTML building blocks shared by the templates. All output is escaped. */
final class Ui
{
    /** Stable hue for a string (used for fallback covers and avatars). */
    public static function hue(string $seed): int
    {
        return crc32($seed) % 360;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== '' && !in_array(mb_strtolower($p), ['ní', 'ó', 'mac', 'nic', 'de', 'uí'], true)));
        if (!$parts) {
            return 'ME';
        }
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last);
    }

    public static function avatar(?array $user, string $size = 'md', ?string $nameOverride = null): string
    {
        $name = $nameOverride ?? ($user['display_name'] ?? 'ME');
        $hue = (int)($user['accent'] ?? self::hue($name));
        $verified = !empty($user['is_verified']) ? '<i class="avatar__tick" title="Verified contributor">✓</i>' : '';
        return '<span class="avatar avatar--' . e($size) . '" style="--h:' . $hue . '"><span>' . e(self::initials($name)) . '</span>' . $verified . '</span>';
    }

    public static function label(string $label): string
    {
        $slug = slugify($label);
        return '<span class="chip chip--label is-' . e($slug) . '"><i></i>' . e($label) . '</span>';
    }

    public static function categoryChip(string $category): string
    {
        return '<a class="chip chip--cat" href="/section/' . e(Categories::slug($category)) . '">' . e($category) . '</a>';
    }

    /** Story card. Variants: feature | standard | compact | row | mini */
    public static function card(array $s, string $variant = 'standard', int $index = 0): string
    {
        $hue = self::hue($s['title']);
        $img = $s['image'] ?? null;
        $media = '<a class="card__media" href="' . e($s['url']) . '" tabindex="-1" aria-hidden="true" style="--h:' . $hue . '">'
            . ($img ? '<img src="' . e($img) . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.closest(\'.card__media\').classList.add(\'is-broken\')">' : '')
            . '<span class="card__fallback"><b>' . e(Categories::ALL[$s['category']]['icon'] ?? '◈') . '</b></span>'
            . '<span class="card__corners"></span></a>';
        $source = $s['kind'] === 'wire'
            ? '<span class="card__source">' . e($s['source_name']) . '</span>'
            : '<span class="card__source card__source--community">Community report</span>';
        $place = $s['location_name'] ? '<span class="card__place">◎ ' . e($s['location_name']) . '</span>' : '';
        $by = $s['author_name'] ? '<span class="card__by">' . e($s['author_name']) . '</span>' : '';
        $meta = '<div class="card__meta">' . self::categoryChip($s['category']) . self::label($s['verification_label']) . '<time datetime="' . e($s['time']) . '">' . e(time_ago($s['time'])) . '</time></div>';
        $summary = $s['summary'] ? '<p class="card__summary">' . e(excerpt($s['summary'], $variant === 'feature' ? 260 : 150)) . '</p>' : '';
        $trust = $s['kind'] === 'community' ? '<div class="meter meter--sm" title="Confidence ' . (int)$s['trust_score'] . '/100"><i style="--v:' . (int)$s['trust_score'] . '"></i></div>' : '';
        $foot = '<div class="card__foot">' . $source . $by . $place . '<span class="card__views">' . e(compact_number((int)$s['views'])) . ' views</span></div>';
        $title = '<h3 class="card__title"><a href="' . e($s['url']) . '">' . e($s['title']) . '</a></h3>';

        return match ($variant) {
            'feature' => '<article class="card card--feature reveal" style="--i:' . $index . '">' . $media . '<div class="card__body">' . $meta . $title . $summary . $trust . $foot . '</div></article>',
            'compact' => '<article class="card card--compact reveal" style="--i:' . $index . '">' . $media . '<div class="card__body">' . $meta . $title . $foot . '</div></article>',
            'row' => '<article class="card card--row reveal" style="--i:' . $index . '"><span class="card__index mono">' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '</span><div class="card__body"><div class="card__meta">' . self::categoryChip($s['category']) . '<time datetime="' . e($s['time']) . '">' . e(time_ago($s['time'])) . '</time></div>' . $title . '<div class="card__foot">' . $source . $place . '</div></div>' . $media . '</article>',
            'mini' => '<article class="card card--mini"><div class="card__body"><div class="card__meta">' . self::categoryChip($s['category']) . '<time>' . e(time_ago($s['time'])) . '</time></div>' . $title . '</div></article>',
            default => '<article class="card card--standard reveal" style="--i:' . $index . '">' . $media . '<div class="card__body">' . $meta . $title . $summary . $trust . $foot . '</div></article>',
        };
    }

    /** Inline SVG sparkline for the newsroom pulse. */
    public static function sparkline(array $values, int $w = 320, int $h = 72): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max(1, max($values));
        $pts = [];
        foreach ($values as $i => $v) {
            $x = round($i / ($n - 1) * ($w - 8) + 4, 1);
            $y = round($h - 6 - ($v / $max) * ($h - 16), 1);
            $pts[] = [$x, $y];
        }
        $line = implode(' ', array_map(static fn($p) => $p[0] . ',' . $p[1], $pts));
        $area = '4,' . ($h - 4) . ' ' . $line . ' ' . ($w - 4) . ',' . ($h - 4);
        [$lx, $ly] = $pts[$n - 1];
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<defs><linearGradient id="sg" x1="0" x2="1"><stop offset="0" stop-color="var(--em)"/><stop offset=".5" stop-color="var(--cy)"/><stop offset="1" stop-color="var(--vi)"/></linearGradient>'
            . '<linearGradient id="sa" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--cy)" stop-opacity=".35"/><stop offset="1" stop-color="var(--cy)" stop-opacity="0"/></linearGradient></defs>'
            . '<polygon points="' . $area . '" fill="url(#sa)"/><polyline points="' . $line . '" fill="none" stroke="url(#sg)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
            . '<circle cx="' . $lx . '" cy="' . $ly . '" r="3.5" class="spark__dot"/></svg>';
    }

    public static function pagination(int $page, int $pages, string $basePath, string $extra = ''): string
    {
        if ($pages <= 1) {
            return '';
        }
        $link = static fn(int $p) => e($basePath . '?page=' . $p . $extra);
        $out = '<nav class="pager mono" aria-label="Pagination">';
        $out .= $page > 1 ? '<a href="' . $link($page - 1) . '">← Prev</a>' : '<span class="is-off">← Prev</span>';
        $out .= '<span class="pager__state">' . $page . ' / ' . $pages . '</span>';
        $out .= $page < $pages ? '<a href="' . $link($page + 1) . '">Next →</a>' : '<span class="is-off">Next →</span>';
        return $out . '</nav>';
    }
}
