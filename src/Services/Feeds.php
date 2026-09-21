<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Locations;

/**
 * Syndication for news aggregators: RSS 2.0 (with Atom, Dublin Core, Media RSS and
 * content:encoded extensions), Atom 1.0, JSON Feed 1.1 and a Google News sitemap.
 *
 * Wire headlines link straight to the publisher (their article is the article); our own
 * community reporting carries the full text, author and image so Google News, Apple News,
 * Flipboard, Feedly, NewsNow and friends can ingest it without scraping.
 */
final class Feeds
{
    public const SITE = 'ME News Ireland';
    public const TAGLINE = 'Your Community. Your News. Live.';

    /** Named feeds: key => [title, filters, ownReportingOnly]. */
    public static function catalogue(): array
    {
        $feeds = [
            'all' => ['title' => self::SITE, 'path' => '/feed.xml', 'filters' => [], 'blurb' => 'Every story: wire headlines linked to their publishers plus our community reporting.'],
            'community' => ['title' => self::SITE . ' — original reporting', 'path' => '/feed/community.xml', 'filters' => ['kind' => 'community'], 'blurb' => 'Only reports written for ME News by our contributors and neighbours. Use this one for Google News and Apple News.'],
        ];
        foreach (Categories::ALL as $name => $meta) {
            $feeds['section:' . $meta['slug']] = ['title' => self::SITE . ' — ' . $name, 'path' => '/feed/section/' . $meta['slug'] . '.xml', 'filters' => ['category' => $name], 'blurb' => $meta['blurb']];
        }
        foreach (Locations::countyNames() as $c) {
            $feeds['county:' . slugify($c)] = ['title' => self::SITE . ' — Co. ' . $c, 'path' => '/feed/county/' . slugify($c) . '.xml', 'filters' => ['county' => $c], 'blurb' => 'Everything happening in County ' . $c . '.'];
        }
        return $feeds;
    }

    public static function resolve(string $key): ?array
    {
        return self::catalogue()[$key] ?? null;
    }

    private static function items(array $filters, int $limit = 50): array
    {
        return Stories::feed($filters, $limit);
    }

    private static function link(array $s): string
    {
        return $s['kind'] === 'wire' && !empty($s['source_url']) ? $s['source_url'] : absolute_url($s['url']);
    }

    private static function author(array $s): string
    {
        return $s['kind'] === 'wire' ? (string)($s['source_author'] ?: $s['source_name']) : (string)($s['author_name'] ?: 'ME News community');
    }

    private static function bodyHtml(array $s): string
    {
        if ($s['kind'] === 'wire') {
            return '<p>' . e((string)($s['summary'] ?: '')) . '</p><p>Read the full story at <a href="' . e((string)$s['source_url']) . '">' . e((string)$s['source_name']) . '</a>.</p>';
        }
        $html = $s['image'] ? '<p><img src="' . e(absolute_url($s['image'])) . '" alt=""></p>' : '';
        return $html . '<p>' . nl2br(e((string)$s['body'])) . '</p><p><a href="' . e(absolute_url($s['url'])) . '">Confirm, comment or add context on ME News</a>.</p>';
    }

    private static function rfc822(string $iso): string
    {
        return gmdate('D, d M Y H:i:s', strtotime($iso) ?: time()) . ' +0000';
    }

    private static function iso(string $iso): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($iso) ?: time());
    }

    public static function rss(array $feed, int $limit = 50): string
    {
        $rows = self::items($feed['filters'], $limit);
        $self = absolute_url($feed['path']);
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        $x .= '<title>' . e($feed['title']) . '</title><link>' . e(absolute_url('/')) . '</link><description>' . e($feed['blurb'] ?? self::TAGLINE) . '</description>';
        $x .= '<language>en-ie</language><copyright>' . e(self::SITE) . '</copyright><generator>ME News ' . e(ME_VERSION) . '</generator><ttl>15</ttl>';
        $x .= '<atom:link href="' . e($self) . '" rel="self" type="application/rss+xml"/>';
        $x .= '<lastBuildDate>' . self::rfc822($rows[0]['time'] ?? now()) . '</lastBuildDate>';
        $x .= '<image><url>' . e(absolute_url('/assets/img/logo-512.png')) . '</url><title>' . e($feed['title']) . '</title><link>' . e(absolute_url('/')) . '</link></image>';
        foreach ($rows as $s) {
            $x .= '<item><title>' . e($s['title']) . '</title><link>' . e(self::link($s)) . '</link><guid isPermaLink="true">' . e(absolute_url($s['url'])) . '</guid>';
            $x .= '<pubDate>' . self::rfc822($s['time']) . '</pubDate><dc:creator>' . e(self::author($s)) . '</dc:creator>';
            $x .= '<category>' . e($s['category']) . '</category>' . ($s['county'] ? '<category>' . e('Co. ' . $s['county']) . '</category>' : '');
            $x .= '<source url="' . e(absolute_url('/feed.xml')) . '">' . e($s['kind'] === 'wire' ? $s['source_name'] : self::SITE) . '</source>';
            $x .= '<description>' . e((string)($s['summary'] ?: excerpt((string)$s['body'], 240))) . '</description>';
            $x .= '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::bodyHtml($s)) . ']]></content:encoded>';
            if ($s['image']) {
                $img = absolute_url($s['image']);
                $x .= '<media:content url="' . e($img) . '" medium="image"><media:title>' . e($s['title']) . '</media:title></media:content><enclosure url="' . e($img) . '" type="image/jpeg" length="0"/>';
            }
            $x .= '</item>';
        }
        return $x . '</channel></rss>';
    }

    public static function atom(array $feed, int $limit = 50): string
    {
        $rows = self::items($feed['filters'], $limit);
        $self = absolute_url(str_replace('.xml', '.atom', $feed['path']));
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en-IE">';
        $x .= '<title>' . e($feed['title']) . '</title><subtitle>' . e($feed['blurb'] ?? self::TAGLINE) . '</subtitle><id>' . e(absolute_url('/')) . '</id>';
        $x .= '<link rel="alternate" type="text/html" href="' . e(absolute_url('/')) . '"/><link rel="self" type="application/atom+xml" href="' . e($self) . '"/>';
        $x .= '<updated>' . self::iso($rows[0]['time'] ?? now()) . '</updated><generator uri="' . e(absolute_url('/')) . '" version="' . e(ME_VERSION) . '">ME News</generator>';
        $x .= '<icon>' . e(absolute_url('/assets/img/favicon.svg')) . '</icon><logo>' . e(absolute_url('/assets/img/logo-512.png')) . '</logo><rights>' . e(self::SITE) . '</rights>';
        foreach ($rows as $s) {
            $x .= '<entry><title>' . e($s['title']) . '</title><id>' . e(absolute_url($s['url'])) . '</id><link rel="alternate" type="text/html" href="' . e(self::link($s)) . '"/>';
            $x .= '<published>' . self::iso($s['time']) . '</published><updated>' . self::iso($s['updated_at'] ?: $s['time']) . '</updated>';
            $x .= '<author><name>' . e(self::author($s)) . '</name></author><category term="' . e($s['category']) . '"/>' . ($s['county'] ? '<category term="' . e('Co. ' . $s['county']) . '"/>' : '');
            $x .= '<summary type="text">' . e((string)($s['summary'] ?: excerpt((string)$s['body'], 240))) . '</summary><content type="html">' . e(self::bodyHtml($s)) . '</content>';
            if ($s['image']) {
                $x .= '<link rel="enclosure" type="image/jpeg" href="' . e(absolute_url($s['image'])) . '"/>';
            }
            $x .= '</entry>';
        }
        return $x . '</feed>';
    }

    public static function json(array $feed, int $limit = 50): array
    {
        $rows = self::items($feed['filters'], $limit);
        return [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $feed['title'], 'home_page_url' => absolute_url('/'), 'feed_url' => absolute_url(str_replace('.xml', '.json', $feed['path'])),
            'description' => $feed['blurb'] ?? self::TAGLINE, 'icon' => absolute_url('/assets/img/logo-512.png'), 'favicon' => absolute_url('/assets/img/favicon.svg'), 'language' => 'en-IE',
            'authors' => [['name' => self::SITE, 'url' => absolute_url('/')]],
            'items' => array_map(static fn($s) => array_filter([
                'id' => absolute_url($s['url']), 'url' => absolute_url($s['url']), 'external_url' => $s['kind'] === 'wire' ? $s['source_url'] : null,
                'title' => $s['title'], 'summary' => $s['summary'] ?: excerpt((string)$s['body'], 240), 'content_html' => self::bodyHtml($s),
                'image' => $s['image'] ? absolute_url($s['image']) : null, 'date_published' => self::iso($s['time']), 'date_modified' => self::iso($s['updated_at'] ?: $s['time']),
                'authors' => [['name' => self::author($s)]], 'tags' => array_values(array_filter([$s['category'], $s['county'] ? 'Co. ' . $s['county'] : null, $s['location_name'] ?: null])),
            ], static fn($v) => $v !== null && $v !== ''), $rows),
        ];
    }

    /** Google News sitemap: our own articles from the last 48 hours (the protocol's limit), newest first. */
    public static function newsSitemap(): string
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - 48 * 3600) . '+00:00';
        $rows = Database::all("SELECT slug,title,category,county,location_name,COALESCE(published_at,created_at) AS t FROM stories WHERE status='published' AND kind='community' AND COALESCE(published_at,created_at)>=? ORDER BY t DESC LIMIT 1000", [$since]);
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';
        foreach ($rows as $s) {
            $keywords = implode(', ', array_filter([$s['category'], $s['county'] ? 'County ' . $s['county'] : null, $s['location_name'] ?: null, 'Ireland']));
            $x .= '<url><loc>' . e(absolute_url('/story/' . $s['slug'])) . '</loc><news:news><news:publication><news:name>' . e(self::SITE) . '</news:name><news:language>en</news:language></news:publication>';
            $x .= '<news:publication_date>' . self::iso($s['t']) . '</news:publication_date><news:title>' . e($s['title']) . '</news:title><news:keywords>' . e($keywords) . '</news:keywords></news:news></url>';
        }
        return $x . '</urlset>';
    }

    public static function opensearch(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/"><ShortName>ME News</ShortName><LongName>' . e(self::SITE) . '</LongName><Description>Search Irish local news, notices and community reports.</Description>'
            . '<Url type="text/html" template="' . e(absolute_url('/search')) . '?q={searchTerms}"/><Url type="application/rss+xml" template="' . e(absolute_url('/feed.xml')) . '"/>'
            . '<Image height="16" width="16" type="image/svg+xml">' . e(absolute_url('/assets/img/favicon.svg')) . '</Image><Language>en-ie</Language><InputEncoding>UTF-8</InputEncoding></OpenSearchDescription>';
    }

    public static function robots(): string
    {
        $lines = ['User-agent: *', 'Allow: /', 'Disallow: /dashboard', 'Disallow: /newsroom', 'Disallow: /api/', 'Disallow: /billing/', 'Disallow: /ads/', '',
            'Sitemap: ' . absolute_url('/sitemap.xml'), 'Sitemap: ' . absolute_url('/news-sitemap.xml')];
        return implode("\n", $lines) . "\n";
    }

    /** Organization record for aggregators and Google's publisher panel. */
    public static function organization(): array
    {
        $same = array_values(array_filter([Database::setting('social_facebook'), Database::setting('social_x'), Database::setting('social_instagram'), Database::setting('social_youtube')]));
        return [
            '@type' => 'NewsMediaOrganization', '@id' => absolute_url('/#organization'), 'name' => self::SITE, 'url' => absolute_url('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => absolute_url('/assets/img/logo-512.png'), 'width' => 512, 'height' => 512],
            'sameAs' => $same, 'foundingLocation' => ['@type' => 'Country', 'name' => 'Ireland'],
            'masthead' => absolute_url('/contributors'), 'ethicsPolicy' => absolute_url('/about#labels'), 'correctionsPolicy' => absolute_url('/corrections'), 'ownershipFundingInfo' => absolute_url('/ownership'),
            'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'newsroom', 'email' => Database::setting('contact_email') ?: Config::get('MAIL_FROM', 'news@menews.ie')],
        ];
    }
}
