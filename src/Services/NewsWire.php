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
 * The Bharat Wire news wire pulls headlines from established Indian publishers (RSS) into the
 * stories table as `kind = wire`. Only the headline, standfirst, image reference, byline and
 * publication time are stored; every story links back to the original publisher.
 *
 * Wire stories are assigned to the Bharat Wire contributor whose desk matches the section so
 * the newsroom has a named person responsible for each part of the feed.
 */
final class NewsWire
{
    private const WORLD_TAGS = ['uk', 'world', 'us', 'europe', 'middle east', 'asia', 'africa', 'americas', 'australia', 'international', 'uk news', 'us news', 'world news'];

    /** Strong signals that a story is about somewhere other than India. */
    private const WORLD_RE = '/\b(trump|white house|washington|us congress|pentagon|kremlin|putin|zelensky|ukraine|russia|gaza|israel|hamas|iran|tehran|beijing|taiwan|nato|united nations|downing street|westminster|10 downing street|starmer|uk government|british government|bank of england|house of commons|scotland|scottish|wales|welsh|nhs|australia|australian|canada|canadian|new zealand|france|french|germany|german|spain|spanish|italy|italian|brussels|european commission|eu summit|macron|merz|nfl|nba|mlb|super bowl|premier league|champions league|europa league|wimbledon|us open golf|french open|australian open|the masters|ryder cup|formula one|f1 grand prix|olympic games)\b/iu';
    private const INDIA_RE = '/\b(pm modi|narendra modi|president murmu|draupadi murmu|lok sabha|rajya sabha|parliament of india|supreme court of india|high court|cbi|enforcement directorate|\bed\b|rbi|sebi|niti aayog|bjp|indian national congress|congress party|aap|trinamool|shiv sena|dmk|aiadmk|jdu|rjd|isro|indian army|indian navy|indian air force|indian government|indian economy|indian exports|indian firms?|indian companies|indian jobs|indian farmers|indian railways|gst council|union budget|reserve bank of india|sensex|nifty|bse|nse|chief minister|governor|\bmp\b|\bmla\b|indian|india|delhi|mumbai|bengaluru|bangalore|chennai|kolkata|hyderabad|pune|ahmedabad|jaipur|lucknow|chandigarh|andhra pradesh|arunachal pradesh|assam|bihar|chhattisgarh|goa|gujarat|haryana|himachal pradesh|jharkhand|karnataka|kerala|madhya pradesh|maharashtra|manipur|meghalaya|mizoram|nagaland|odisha|punjab|rajasthan|sikkim|tamil nadu|telangana|tripura|uttar pradesh|uttarakhand|west bengal|jammu and kashmir|ladakh|puducherry)\b/iu';
    /** A single strong term is enough for Sport; weak terms need two of them. */
    private const SPORT_STRONG_RE = '/\b(cricket|ipl|bcci|t20|test match|odi|ranji trophy|world cup|premier league|champions league|europa league|isl|indian super league|i-league|kabaddi|pro kabaddi|hockey india|badminton|pv sindhu|neeraj chopra|virat kohli|rohit sharma|ms dhoni|nfl|nba|mlb|nhl|super bowl|wimbledon|ryder cup|tour de france|grand slam|match report|player ratings|kick-?off|us open|french open|australian open|open championship|the masters|jockey|snooker|darts|olympic|paralympic|asian games|commonwealth games|tennis|marathon|formula one|f1|grand prix|nations league|india (?:beat|lose|win|v |vs)|man united|man utd|liverpool fc|arsenal|chelsea|real madrid|barcelona)\b/iu';
    private const SPORT_WEAK_RE = '/\b(final|semi-final|quarter-final|championship|goals?|scored|scorer|manager|coach|striker|midfielder|defender|goalkeeper|racing|golf|boxing|bout|title fight|athletics|swimming|cycling|motorsport|fixtures|results|squad|captain|clash|derby|replay|penalty|penalties|injury time|second half|first half|extra time|referee|umpire|title race|points table|relegation|promotion|play-?offs?|trophy|medal|podium|wicket|century|innings|bowler|batsman|batter|run rate|under-\d+|u\d+s?)\b/iu';
    private const BUSINESS_RE = '/\b(gdp|inflation|interest rates?|rbi|repo rate|central bank|shares|stock market|sensex|nifty|bse|nse|dow jones|nasdaq|profits?|revenue|earnings|takeover|acquisition|merger|ipo|investors?|start-?up|venture capital|exports?|imports?|tariffs?|trade deal|recession|union budget|gst|corporate tax|multinational|pharma|reliance|adani|tata group|price of|prices|oil|crude|barrel|opec|per litre|mortgage|property of the week|on the market|for sale|asking price|house prices|property market|rents?|landlords?|retail sales|consumer|employment figures|unemployment|jobs announced|layoffs)\b/iu';
    private const CULTURE_RE = '/\b(album|single|tour dates|concert|gig|festival|mela|theatre|play|novel|book|author|grammy|podcast|exhibition|gallery|museum|art|artist|singer|band|dj|comedian)\b/iu';
    /** Bollywood/television/entertainment — distinct from Culture (arts/books/music/theatre). */
    private const ENTERTAINMENT_RE = '/\b(film|movie|cinema|netflix|prime video|hotstar|series|season \d|episode|oscar|bafta|filmfare|iifa|national film award|bollywood|tollywood|kollywood|sandalwood|mollywood|ott|actor|actress|celebrity|box office|trailer|teaser|documentary|streaming|tv show|reality show|soap opera|serial)\b/iu';
    /** Technology — gadgets, software, AI, startups' products, not general business/markets. */
    private const TECH_RE = '/\b(smartphone|iphone|android|ios update|app store|play store|launched a new|chipset|processor|semiconductor|artificial intelligence|\bai\b|machine learning|chatbot|large language model|software update|app update|cybersecurity|data breach|hacked|hackers?|data centre|cloud computing|5g|6g|wi-?fi|satellite internet|electric vehicle|\bev\b|battery tech|robotics|drone (?:delivery|startup)|quantum computing|tech giant|apple|google|meta platforms|microsoft|amazon web services|infosys|tcs|wipro|byju|paytm|zomato|swiggy|ola electric|flipkart|gadget|laptop|tablet|wearable|smartwatch|nvidia|intel|qualcomm|spacex|starlink)\b/iu';
    /** Defence technology and the armed forces. */
    private const DEFENCE_RE = '/\b(drdo|hal\b|bharat electronics|\bbel\b|brahmos|agni missile|akash missile|tejas jet|rafale|indian army|indian navy|indian air force|\bias\b|\bins\b (?:vikrant|vikramaditya)|submarine|fighter jet|missile test|missile system|air defence|defence ministry|ministry of defence|defence budget|defence deal|defence procurement|arms deal|military exercise|border security force|\bbsf\b|indo-pak border|line of control|\bloc\b|line of actual control|\blac\b|army chief|navy chief|air chief marshal|chief of defence staff|paramilitary|special forces|para commandos|garud commandos|marcos|nsg commandos|radar system|surveillance drone|combat drone|unmanned aerial|artillery|howitzer|warship|aircraft carrier|stealth frigate|anti-tank|counter-terror operation)\b/iu';

    /** Editorial desk → contributor handle. */
    private const DESKS = [
        'National' => 'aisha', 'World' => 'aisha', 'Council' => 'rohan', 'Defence' => 'aisha',
        'Sport' => 'arjun', 'Culture' => 'meera', "What's On" => 'meera', 'Entertainment' => 'meera',
        'Business' => 'priya', 'Technology' => 'priya', 'Local' => 'rohan', 'Traffic' => 'rohan', 'Community' => 'rohan',
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
            // Local outlets (a city edition of a national paper) default to their state unless the story is plainly national.
            if (preg_match('/\b(prime minister|pm modi|lok sabha|rajya sabha|parliament|cabinet|nationwide|union budget|imd warning|supreme court of india|cbi director|across india|in india|indian citizens)\b/iu', $text)) {
                $category = $category === 'Local' ? 'National' : $category;
            } else {
                $county = $source['county'];
            }
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
            'location_name' => $town ?? $county,
            'language' => $source['language'] ?? 'en',
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
            if (in_array($tag, ['technology', 'tech', 'gadgets', 'ai', 'startups'], true)) {
                $category = 'Technology';
            } elseif (in_array($tag, ['business', 'indian business', 'property', 'personal finance', 'markets'], true)) {
                $category = 'Business';
            } elseif (in_array($tag, ['regional', 'delhi news', 'local news', 'mumbai news', 'north india', 'south india', 'east india', 'west india'], true)) {
                $category = 'Local';
            } elseif (in_array($tag, ['entertainment', 'movies', 'film', 'bollywood', 'tv & radio'], true)) {
                $category = 'Entertainment';
            } elseif (in_array($tag, ['culture', 'arts', 'music', 'books', 'lifestyle', 'life & style'], true)) {
                $category = 'Culture';
            } elseif (in_array($tag, ['defence', 'defense', 'military'], true)) {
                $category = 'Defence';
            } elseif (in_array($tag, ['cricket', 'rugby', 'soccer', 'football', 'hockey', 'kabaddi', 'racing', 'golf', 'athletics', 'sport', 'boxing', 'other sports'], true) && $category !== 'Sport') {
                $category = 'Sport';
            }
        }
        $text = $title . ' ' . $summary;
        $t = mb_strtolower($text);
        $sportFeed = ($source['category'] ?? '') === 'Sport' || $category === 'Sport';
        // Sport first: an NFL game or a tennis final is Sport, wherever it happened.
        $notSport = preg_match('/\b(police|court|crash|collision|council|planning|died|death|funeral|hospital|₹|bank|tax|budget|minister|parliament|election|concert|gig|tour dates|album|singer|announces .{0,20}dates)\b/iu', $title);
        if (!$sportFeed && !$notSport && (preg_match(self::SPORT_STRONG_RE, $title) || preg_match_all(self::SPORT_WEAK_RE, $title) >= 2)) {
            $category = 'Sport';
        }
        $notCrimeOrPolitics = !preg_match('/\b(police|court|crash|died|killed|arrested|minister|parliament|election)\b/iu', $title);
        // Defence: checked before World, since border/neighbour stories would otherwise misfire as foreign news.
        if (in_array($category, ['National', 'Local', 'World'], true) && preg_match(self::DEFENCE_RE, $title)) {
            return 'Defence';
        }
        // World: strong foreign markers in the headline and no India anchor.
        if (in_array($category, ['National', 'Local', 'Business', 'Technology', 'Culture', 'Entertainment'], true) && preg_match(self::WORLD_RE, $title) && !preg_match(self::INDIA_RE, $title)) {
            return 'World';
        }
        if (in_array($category, ['National', 'Local'], true)) {
            if (!str_contains($t, 'air traffic') && preg_match('/\b(metro|local train|highway|expressway|flyover|nh-?\d+|toll plaza|road closed|road closure|road traffic|traffic updates|traffic jam|traffic chaos|traffic delays|gridlock|road accident|road crash|road collision|multi-vehicle)\b/u', $t)) {
                return 'Traffic';
            }
            if (preg_match('/\b(municipal corporation|municipal council|civic body|zilla parishad|gram panchayat|panchayat|district magistrate|collector\'s office|urban development authority|master plan|building permission|encroachment drive|planning permission|local authority)\b/u', $t)) {
                return 'Council';
            }
            if (preg_match('/\b(festival|mela|gig|concert|exhibition|parade|things to do|what\'s on|line-?up announced|tickets go on sale)\b/u', $t)) {
                return "What's On";
            }
            if (preg_match(self::TECH_RE, $title) && $notCrimeOrPolitics) {
                return 'Technology';
            }
            if (preg_match(self::BUSINESS_RE, $title) && $notCrimeOrPolitics) {
                return 'Business';
            }
            if (preg_match(self::ENTERTAINMENT_RE, $title)) {
                return 'Entertainment';
            }
            if (preg_match(self::CULTURE_RE, $title) && preg_match('/\b(review|stars?|premiere|releases?|tour|lineup|line-up|wins|nominated|interview)\b/iu', $title)) {
                return 'Culture';
            }
        }
        // A Business-desk story that's really about gadgets/software, not markets.
        if ($category === 'Business' && preg_match(self::TECH_RE, $title) && !preg_match(self::BUSINESS_RE, $title)) {
            return 'Technology';
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
            'author_name' => $contributor['display_name'] ?? 'Bharat Wire Desk',
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
            'language' => $story['language'] ?? 'en',
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
        $handle = self::DESKS[$category] ?? 'rohan';
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
