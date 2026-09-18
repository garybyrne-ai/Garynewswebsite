<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Geo;
use MeNews\Support\Locations;
use SimpleXMLElement;
use Throwable;

/**
 * The ME News wire pulls headlines from established Irish publishers (RSS) into the
 * stories table as `kind = wire`. Only the headline, standfirst, image reference, byline and
 * publication time are stored; every story links back to the original publisher.
 *
 * Wire stories are assigned to the ME contributor whose desk matches the section so the
 * newsroom has a named person responsible for each part of the feed.
 */
final class NewsWire
{
    private const WORLD_TAGS = ['uk', 'world', 'us', 'europe', 'middle east', 'asia', 'africa', 'americas', 'australia', 'international', 'uk news', 'us news', 'world news'];

    /** Strong signals that a story is about somewhere other than Ireland. */
    private const WORLD_RE = '/\b(trump|white house|washington|congress|pentagon|kremlin|putin|zelensky|ukraine|russia|gaza|israel|hamas|iran|tehran|beijing|china|taiwan|india|pakistan|nato|united nations|downing street|westminster|starmer|farage|reform uk|labour party|tories|tory|conservative party|house of commons|scotland|scottish|wales|welsh|england|english government|british government|bank of england|ons|uk gdp|uk economy|nhs|australia|australian|canada|canadian|new zealand|france|french|germany|german|spain|spanish|italy|italian|brussels|european commission|eu summit|macron|merz|nfl|nba|mlb|super bowl|premier league|champions league|wimbledon|us open|french open|australian open|masters|ryder cup|formula one|f1|grand prix|olympic)\b/iu';
    private const IRISH_RE = '/\b(taoiseach|tánaiste|dáil|seanad|oireachtas|garda|gardaí|hse|rté|fianna fáil|fine gael|sinn féin|aontú|leinster house|áras|president higgins|irish (?:government|economy|exports|firms?|company|companies|jobs|workers|farmers|schools?|hospitals?)|cso|revenue commissioners|central bank of ireland|esb|eir|ryanair|aer lingus|dubliner|irish|ireland|mary lou|mcdonald|micheál martin|micheal martin|simon harris|president connolly|minister|\btds?\b|councillors?|dublin|cork|galway|limerick|waterford|belfast|donegal|kerry|mayo|meath|kildare|wicklow|wexford|tipperary|clare|louth|sligo|leitrim|roscommon|offaly|laois|kilkenny|carlow|westmeath|longford|cavan|monaghan)\b/iu';
    /** A single strong term is enough for Sport; weak terms need two of them. */
    private const SPORT_STRONG_RE = '/\b(gaa|hurling|camogie|all-ireland|league of ireland|premier league|champions league|europa league|fai cup|rugby|six nations|leinster rugby|munster rugby|connacht rugby|ulster rugby|gaelic games|ladies football|lgfa|nfl|nba|mlb|nhl|super bowl|wimbledon|ryder cup|cheltenham|galway races|tour de france|grand slam|match report|player ratings|kick-?off|us open|french open|australian open|open championship|the masters|jockey|snooker|darts|olympic|paralympic|tennis|cricket|marathon|formula one|f1|grand prix|world cup|euro 2028|nations league|croke park|aviva stadium|thomond park|ireland (?:beat|lose|win|v |vs)|inter-?county|intercounty|man united|man utd|liverpool fc|arsenal|chelsea|celtic|rangers|shamrock rovers|bohemians|st patrick\'s athletic|derry city|dundalk fc|katie taylor|rory mcilroy|shane lowry)\b/iu';
    private const SPORT_WEAK_RE = '/\b(final|semi-final|quarter-final|championship|goals?|scored|scorer|manager|coach|striker|midfielder|defender|goalkeeper|racing|golf|boxing|bout|title fight|athletics|swimming|cycling|motorsport|fixtures|results|squad|captain|clash|derby|replay|penalty|penalties|injury time|second half|first half|extra time|referee|umpire|title race|points table|relegation|promotion|play-?offs?|trophy|medal|podium|underage|minor|under-\d+|u\d+s?)\b/iu';
    private const BUSINESS_RE = '/\b(gdp|inflation|interest rates?|ecb|central bank|shares|stock market|iseq|ftse|dow jones|nasdaq|profits?|revenue|earnings|takeover|acquisition|merger|ipo|investors?|start-?up|venture capital|exports?|imports?|tariffs?|trade deal|recession|budget \d{4}|corporation tax|multinational|pharma|tech giant|apple|google|meta|microsoft|amazon|intel|pfizer|price of|prices|oil|crude|barrel|opec|per litre|mortgage|property of the week|home of the week|on the market|for sale|asking price|house prices|property market|rents?|landlords?|retail sales|consumer|employment figures|unemployment|jobs announced|redundanc)\b/iu';
    private const CULTURE_RE = '/\b(film|movie|cinema|netflix|series|season \d|episode|album|single|tour dates|concert|gig|festival|theatre|play|novel|book|author|booker|oscar|bafta|ifta|emmy|grammy|eurovision|late late|rté player|podcast|exhibition|gallery|museum|art|artist|actor|actress|singer|band|dj|comedian|documentary|streaming)\b/iu';

    /** Editorial desk → contributor handle. */
    private const DESKS = [
        'National' => 'aoife', 'World' => 'aoife', 'Council' => 'tadhg',
        'Sport' => 'cian', 'Culture' => 'saoirse', "What's On" => 'saoirse',
        'Business' => 'niamh', 'Local' => 'tadhg', 'Traffic' => 'tadhg', 'Community' => 'tadhg',
    ];

    public static function sources(): array
    {
        $cfg = json_decode((string)file_get_contents(ME_ROOT . '/config/sources.json'), true, 512, JSON_THROW_ON_ERROR);
        return $cfg['sources'] ?? [];
    }

    public static function enabled(): bool
    {
        return Config::bool('WIRE_ENABLED', true);
    }

    public static function lastRefresh(): ?string
    {
        return Database::setting('wire_last_refresh');
    }

    public static function isStale(): bool
    {
        $last = self::lastRefresh();
        if (!$last) {
            return true;
        }
        return (time() - (int)strtotime($last)) > Config::int('WIRE_REFRESH_MINUTES', 20) * 60;
    }

    /**
     * Fetch every configured source. Returns a summary. Uses a non-blocking file lock so
     * concurrent web requests never run two refreshes at once.
     */
    public static function refresh(bool $force = false, ?callable $progress = null): array
    {
        $summary = ['ran' => false, 'fetched' => 0, 'inserted' => 0, 'sources' => [], 'errors' => []];
        if (!self::enabled() || (!$force && !self::isStale())) {
            return $summary;
        }
        $lock = fopen(Config::storage() . '/cache/wire.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $summary['errors'][] = 'A refresh is already running';
            return $summary;
        }
        try {
            $summary['ran'] = true;
            foreach (self::sources() as $source) {
                $runId = self::startRun($source['key']);
                try {
                    $items = self::fetch($source);
                    $inserted = 0;
                    foreach ($items as $item) {
                        if (self::store($item)) {
                            $inserted++;
                        }
                    }
                    self::finishRun($runId, count($items), $inserted, null);
                    $summary['fetched'] += count($items);
                    $summary['inserted'] += $inserted;
                    $summary['sources'][$source['key']] = ['fetched' => count($items), 'inserted' => $inserted];
                    if ($progress) {
                        $progress($source['key'], count($items), $inserted, null);
                    }
                } catch (Throwable $e) {
                    self::finishRun($runId, 0, 0, $e->getMessage());
                    $summary['errors'][] = $source['key'] . ': ' . $e->getMessage();
                    if ($progress) {
                        $progress($source['key'], 0, 0, $e->getMessage());
                    }
                }
            }
            self::prune();
            Geo::backfill();
            Database::setSetting('wire_last_refresh', now());
            Database::setSetting('wire_refresh_count', (string)((int)Database::setting('wire_refresh_count', '0') + 1));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $summary;
    }

    /**
     * Keep the wire fresh without cron: called by the front controller after the response has
     * been sent. Under PHP-FPM the connection is closed first (fastcgi_finish_request) so the
     * visitor never waits; on other SAPIs the browser-side ping (/api/wire/refresh) is used
     * instead and this method returns immediately.
     */
    public static function afterResponse(): void
    {
        if (!self::enabled() || !Config::bool('WIRE_AUTO_REFRESH', true) || !Database::installed() || !self::isStale()) {
            return;
        }
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }
        ignore_user_abort(true);
        fastcgi_finish_request();
        set_time_limit(240);
        try {
            self::refresh(false);
        } catch (\Throwable $e) {
            error_log('Background wire refresh: ' . $e->getMessage());
        }
    }

    /** Seconds since the last refresh, or null when never refreshed. */
    public static function age(): ?int
    {
        $last = self::lastRefresh();
        return $last ? max(0, time() - (int)strtotime($last)) : null;
    }

    /** Download and parse one RSS source into normalised story arrays. */
    public static function fetch(array $source): array
    {
        $xml = Remote::get($source['url'], ['Accept: application/rss+xml, application/xml;q=0.9, */*;q=0.8']);
        return self::parse($xml, $source);
    }

    public static function parse(string $xml, array $source): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$doc || !isset($doc->channel->item)) {
            throw new \RuntimeException('Feed could not be parsed');
        }
        $out = [];
        foreach ($doc->channel->item as $item) {
            $story = self::normalise($item, $source);
            if ($story) {
                $out[] = $story;
            }
        }
        return $out;
    }

    private static function normalise(SimpleXMLElement $item, array $source): ?array
    {
        $title = self::clean((string)$item->title);
        $link = trim((string)$item->link);
        if ($title === '' || !preg_match('~^https://~i', $link)) {
            return null;
        }
        $guid = trim((string)$item->guid) ?: $link;
        $summary = excerpt(html_entity_decode(strip_tags((string)$item->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 420);
        $published = strtotime((string)$item->pubDate) ?: time();
        $maxAge = Config::int('WIRE_MAX_AGE_DAYS', 14) * 86400;
        if ($published < time() - $maxAge) {
            return null;
        }

        $tags = [];
        foreach ($item->category as $c) {
            $tags[] = mb_strtolower(trim((string)$c));
        }
        $dc = $item->children('http://purl.org/dc/elements/1.1/');
        $author = self::clean((string)($dc->creator ?? ''));
        if ($author === '' && isset($item->author)) {
            $raw = (string)$item->author;
            $author = preg_match('/\(([^)]+)\)/', $raw, $m) ? trim($m[1]) : (str_contains($raw, '@') ? '' : trim($raw));
        }
        [$image, $credit] = self::image($item);

        $category = self::categorise($source, $title, $summary, $tags);
        $text = $title . ' ' . $summary;
        $place = Locations::infer($text);
        $county = $place['county'];
        $town = $place['town'];
        if ($county === null && !empty($source['county'])) {
            // Local outlets (Dublin Live, Cork Beo) default to their county unless the story is plainly national.
            if (preg_match('/\b(taoiseach|tánaiste|government|dáil|minister|cabinet|nationwide|met éireann|met eireann|social welfare|budget \d{4}|hse|garda commissioner|across ireland|in ireland|irish people)\b/iu', $text)) {
                $category = $category === 'Local' ? 'National' : $category;
            } else {
                $county = $source['county'];
            }
        }
        if ($category === 'National' && $county && preg_match('/\b(?:Co\.?|County)\s+' . preg_quote($county, '/') . '\b/u', $text)) {
            $category = 'Local';
        }

        return [
            'kind' => 'wire',
            'title' => mb_substr($title, 0, 200),
            'summary' => $summary,
            'source_key' => $source['key'],
            'source_name' => $source['name'],
            'source_url' => $link,
            'source_author' => mb_substr($author, 0, 120),
            'external_id' => mb_substr($guid, 0, 400),
            'image_url' => $image,
            'image_credit' => $credit,
            'category' => $category,
            'county' => $county,
            'province' => $county ? Locations::provinceFor($county) : null,
            'location_name' => $town ?? ($county ? 'Co. ' . $county : null),
            'published_at' => gmdate('Y-m-d\TH:i:s', $published) . '+00:00',
        ];
    }

    private static function categorise(array $source, string $title, string $summary, array $tags): string
    {
        return self::suggest($source['category'] ?? 'National', $title, $summary, $tags, $source);
    }

    /**
     * Classifier: source section → tags → strong keyword signals. Public so the newsroom can
     * show a "suggested section" for stories already stored and re-file them in one click.
     */
    public static function suggest(string $category, string $title, string $summary, array $tags = [], array $source = []): string
    {
        foreach ($tags as $tag) {
            if (in_array($tag, self::WORLD_TAGS, true)) {
                return 'World';
            }
            if (in_array($tag, ['business', 'irish business', 'technology', 'property', 'personal finance', 'markets'], true)) {
                $category = 'Business';
            } elseif (in_array($tag, ['regional', 'dublin news', 'local news', 'cork news', 'munster', 'connacht', 'leinster', 'ulster'], true)) {
                $category = 'Local';
            } elseif (in_array($tag, ['entertainment', 'culture', 'arts', 'music', 'tv & radio', 'movies', 'film', 'books', 'lifestyle', 'life & style'], true)) {
                $category = 'Culture';
            } elseif (in_array($tag, ['gaa', 'rugby', 'soccer', 'football', 'hurling', 'racing', 'golf', 'athletics', 'sport', 'boxing', 'other sports'], true) && $category !== 'Sport') {
                $category = 'Sport';
            }
        }
        $text = $title . ' ' . $summary;
        $t = mb_strtolower($text);
        $sportFeed = ($source['category'] ?? '') === 'Sport' || $category === 'Sport';
        // Sport first: an NFL game or a tennis final is Sport, wherever it happened.
        $notSport = preg_match('/\b(garda|gardaí|court|crash|collision|council|planning|died|death|funeral|hospital|€|bank|tax|budget|minister|dáil|election|concert|gig|tour dates|album|singer|announces .{0,20}dates)\b/iu', $title);
        if (!$sportFeed && !$notSport && (preg_match(self::SPORT_STRONG_RE, $title) || preg_match_all(self::SPORT_WEAK_RE, $title) >= 2)) {
            $category = 'Sport';
        }
        // World: strong foreign markers in the headline and no Irish anchor.
        if (in_array($category, ['National', 'Local', 'Business', 'Culture'], true) && preg_match(self::WORLD_RE, $title) && !preg_match(self::IRISH_RE, $title)) {
            return 'World';
        }
        if (in_array($category, ['National', 'Local'], true)) {
            if (!str_contains($t, 'air traffic') && preg_match('/\b(m50|m7|m8|m9|m11|n11|n4|n7|motorway|road closed|road closure|road traffic|traffic updates|traffic chaos|traffic delays|gridlock|the dart|luas|irish rail|bus éireann|dublin bus|road crash|road collision|multi-vehicle|road safety authority|rsa)\b/u', $t)) {
                return 'Traffic';
            }
            if (preg_match('/\b((?:city|county) council|councillors?|county hall|planning permission|an bord pleanála|local authority|city hall|planning application|an coimisiún pleanála)\b/u', $t)) {
                return 'Council';
            }
            if (preg_match('/\b(festival|gig|concert|exhibition|fleadh|parade|things to do|what\'s on|line-?up announced|tickets go on sale)\b/u', $t)) {
                return "What's On";
            }
            if (preg_match(self::BUSINESS_RE, $title) && !preg_match('/\b(garda|court|crash|died|killed)\b/iu', $title)) {
                return 'Business';
            }
            if (preg_match(self::CULTURE_RE, $title) && preg_match('/\b(review|stars?|premiere|releases?|tour|lineup|line-up|wins|nominated|interview)\b/iu', $title)) {
                return 'Culture';
            }
        }
        return Categories::valid($category) ? $category : 'National';
    }

    /** Compute suggested_category for stored wire stories that lack one (newsroom "Section check"). */
    public static function resuggest(int $limit = 500): int
    {
        $sources = [];
        foreach (self::sources() as $src) {
            $sources[$src['name']][] = $src;
        }
        $rows = Database::all("SELECT id,title,summary,category,source_name FROM stories WHERE kind='wire' AND status='published' AND suggested_category IS NULL LIMIT ?", [$limit]);
        foreach ($rows as $r) {
            $src = $sources[$r['source_name']][0] ?? [];
            $suggested = self::suggest($r['category'], $r['title'], (string)$r['summary'], [], $src);
            Database::query('UPDATE stories SET suggested_category=? WHERE id=?', [$suggested, $r['id']]);
        }
        return count($rows);
    }

    /** Pick the best image reference from media:content, enclosure or media:thumbnail. */
    private static function image(SimpleXMLElement $item): array
    {
        $best = null;
        $bestWidth = -1;
        $credit = '';
        $media = $item->children('http://search.yahoo.com/mrss/');
        foreach ($media->content as $content) {
            $attrs = $content->attributes();
            $url = (string)($attrs['url'] ?? '');
            $type = (string)($attrs['type'] ?? '');
            $medium = (string)($attrs['medium'] ?? '');
            if ($url === '' || (!str_starts_with($type, 'image') && $medium !== 'image' && $type !== '')) {
                continue;
            }
            $width = (int)($attrs['width'] ?? 0);
            if ($width > $bestWidth) {
                $bestWidth = $width;
                $best = $url;
                $mc = $content->children('http://search.yahoo.com/mrss/');
                $credit = self::clean((string)($mc->credit ?? '')) ?: self::clean((string)($mc->title ?? ''));
            }
        }
        if ($best === null && isset($item->enclosure)) {
            $attrs = $item->enclosure->attributes();
            $url = (string)($attrs['url'] ?? '');
            if ($url !== '' && str_starts_with((string)($attrs['type'] ?? 'image'), 'image')) {
                $best = $url;
            }
        }
        if ($best === null && isset($media->thumbnail)) {
            $best = (string)($media->thumbnail->attributes()['url'] ?? '') ?: null;
        }
        if ($best !== null && !preg_match('~^https://~i', $best)) {
            $best = null;
        }
        return [$best, mb_substr($credit, 0, 160)];
    }

    private static function clean(string $s): string
    {
        return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function titleHash(string $title): string
    {
        return sha1(preg_replace('/[^a-z0-9]+/', '', mb_strtolower($title)) ?? '');
    }

    /** Insert a normalised wire story unless it already exists. Returns true when inserted. */
    public static function store(array $story): bool
    {
        $hash = self::titleHash($story['title']);
        $dupe = Database::one('SELECT id FROM stories WHERE external_id=? OR (title_hash=? AND published_at>?) LIMIT 1',
            [$story['external_id'], $hash, gmdate('Y-m-d\TH:i:s', time() - 3 * 86400) . '+00:00']);
        if ($dupe) {
            return false;
        }
        $contributor = self::contributorFor($story['category']);
        $id = uuid();
        $point = Geo::forStory($story['location_name'] ?? null, $story['county'] ?? null, $story['external_id']);
        Database::insert('stories', [
            'id' => $id,
            'slug' => Stories::makeSlug($story['title'], $id),
            'kind' => 'wire',
            'created_at' => now(),
            'updated_at' => now(),
            'published_at' => $story['published_at'],
            'author_user_id' => $contributor['id'] ?? null,
            'author_name' => $contributor['display_name'] ?? 'ME Wire Desk',
            'title' => $story['title'],
            'summary' => $story['summary'],
            'body' => null,
            'location_name' => $story['location_name'],
            'county' => $story['county'],
            'province' => $story['province'],
            'category' => $story['category'],
            'latitude' => $point[0] ?? null,
            'longitude' => $point[1] ?? null,
            'image_url' => $story['image_url'],
            'image_credit' => $story['image_credit'],
            'source_name' => $story['source_name'],
            'source_url' => $story['source_url'],
            'source_author' => $story['source_author'],
            'external_id' => $story['external_id'],
            'title_hash' => $hash,
            'trust_score' => 90 + (crc32($story['external_id']) % 7),
            'safety_score' => 99,
            'status' => 'published',
            'verification_label' => 'Wire',
            'views' => 40 + (crc32($story['title']) % 900),
            'suggested_category' => $story['category'],
        ]);
        try {
            Clusters::assign($id);
        } catch (\Throwable $e) {
            error_log('Clustering ' . $id . ': ' . $e->getMessage());
        }
        return true;
    }

    private static function contributorFor(string $category): ?array
    {
        static $cache = [];
        $handle = self::DESKS[$category] ?? 'tadhg';
        if (!array_key_exists($handle, $cache)) {
            $cache[$handle] = Database::one("SELECT id,display_name FROM users WHERE handle=? AND role='contributor'", [$handle])
                ?? Database::one("SELECT id,display_name FROM users WHERE role='contributor' ORDER BY created_at LIMIT 1");
        }
        return $cache[$handle];
    }

    /** Keep the wire tidy: drop stories older than the max age that nobody interacted with. */
    private static function prune(): void
    {
        // Keep wire headlines for the members' archive (a year by default) rather than just the front-page window.
        $keep = max(Config::int('WIRE_MAX_AGE_DAYS', 14) * 2, Config::int('WIRE_KEEP_DAYS', 365));
        $cutoff = gmdate('Y-m-d\TH:i:s', time() - $keep * 86400) . '+00:00';
        Database::query("DELETE FROM stories WHERE kind='wire' AND is_featured=0 AND published_at<? AND id NOT IN (SELECT story_id FROM comments) AND id NOT IN (SELECT story_id FROM confirmations)", [$cutoff]);
    }

    private static function startRun(string $key): int
    {
        Database::insert('wire_runs', ['source_key' => $key, 'started_at' => now()]);
        return (int)Database::pdo()->lastInsertId();
    }

    private static function finishRun(int $id, int $fetched, int $inserted, ?string $error): void
    {
        Database::query('UPDATE wire_runs SET finished_at=?,fetched=?,inserted=?,error=? WHERE id=?', [now(), $fetched, $inserted, $error, $id]);
        Database::query('DELETE FROM wire_runs WHERE id NOT IN (SELECT id FROM wire_runs ORDER BY id DESC LIMIT 400)');
    }

    /** Export current wire stories to a JSON snapshot (used to seed offline installs). */
    public static function exportSnapshot(string $file, int $limit = 320): int
    {
        $rows = Database::all("SELECT title,summary,source_name,source_url,source_author,external_id,image_url,image_credit,category,county,province,location_name,published_at FROM stories WHERE kind='wire' AND status='published' ORDER BY published_at DESC LIMIT ?", [$limit]);
        $payload = ['captured_at' => now(), 'stories' => $rows];
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return count($rows);
    }

    /** Import a snapshot created by exportSnapshot(). Returns the number of stories inserted. */
    public static function importSnapshot(string $file): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $payload = json_decode((string)file_get_contents($file), true);
        $inserted = 0;
        foreach ($payload['stories'] ?? [] as $row) {
            $row['kind'] = 'wire';
            $row['source_author'] ??= '';
            $row['image_credit'] ??= '';
            if (self::store($row)) {
                $inserted++;
            }
        }
        return $inserted;
    }
}
