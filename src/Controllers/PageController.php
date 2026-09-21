<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Media;
use MeNews\Services\NewsWire;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Locations;
use MeNews\View;

/** Server-rendered public pages. */
final class PageController
{
    private static function base(array $extra = []): array
    {
        return [
            'user' => Auth::user(),
            'nav' => Categories::NAV,
            'wireLast' => NewsWire::lastRefresh(),
        ] + $extra;
    }

    public static function home(Request $r): Response
    {
        $locality = \MeNews\Support\Visitor::locality(8);
        $county = $locality['county'] ?? null;
        $hero = Stories::hero($county);
        $exclude = $hero ? [$hero['id']] : [];
        $latest = Stories::feed(['exclude' => $exclude], 9);
        $exclude = array_merge($exclude, array_column($latest, 'id'));
        $sections = [];
        foreach (['National', 'Local', 'Sport', 'Business', 'Culture', 'World'] as $cat) {
            $rows = Stories::feed(['category' => $cat, 'exclude' => $exclude], 4);
            if ($rows) {
                $sections[$cat] = $rows;
                $exclude = array_merge($exclude, array_column($rows, 'id'));
            }
        }
        $community = Stories::feed(['kind' => 'community'], 4);
        $leadKicker = $hero && $county && $hero['county'] === $county ? 'Lead story · Co. ' . e($county) : ($hero && $hero['is_featured'] ? 'Editor\'s pick' : 'Lead story · Ireland');
        return View::page('home', self::base([
            'title' => ($county ? $county . ' news today — ' : '') . 'ME News Ireland — Your Community. Your News. Live.',
            'description' => ($county ? 'Local news, deaths and notices, school closures and weather warnings for County ' . $county . ', plus ' : 'Ireland-wide news, county-by-county reporting and community stories, ') . 'the stories Ireland is voting for on the Signal.',
            'hero' => $hero,
            'leadKicker' => $leadKicker,
            'latest' => $latest,
            'ticker' => Stories::ticker(16),
            'sections' => $sections,
            'community' => $community,
            'trending' => Stories::trending(6),
            'counties' => Stories::countyActivity(8),
            'mapPoints' => Stories::mapPoints(120),
            'mapCounties' => Stories::countyPoints(),
            'ads' => self::ads((string)$county, '', 2, 'home'),
            'banner' => self::banner((string)$county, '', 'home'),
            'weather' => \MeNews\Services\Weather::today(),
            'warnings' => \MeNews\Services\Alerts::headline($county),
            'edition' => \MeNews\Support\Daily::edition(),
            'longDate' => \MeNews\Support\Daily::longDate(),
            'focal' => \MeNews\Support\Daily::focal(),
            'greeting' => \MeNews\Support\Daily::greeting(),
            'crossword' => \MeNews\Services\Puzzles::crossword(\MeNews\Support\Daily::date(), 'junior'),
            'quiz' => \MeNews\Services\Puzzles::quiz(\MeNews\Support\Daily::date()),
            'youngReaders' => \MeNews\Controllers\KidsController::youngReaders(3),
            'locality' => $locality,
            'countyNames' => Locations::countyNames(),
            'countyNotices' => $county ? \MeNews\Services\Notices::recent(['county' => $county, 'kind' => 'death'], 4) : [],
            'latestNotices' => \MeNews\Services\Notices::recent($county ? ['county' => $county] : [], 5),
            'whatsapp' => Database::setting('whatsapp_' . slugify((string)$county)) ?: Database::setting('whatsapp_number'),
            'poll' => \MeNews\Services\Polls::current($county),
            'signalBoard' => \MeNews\Services\Signal::featured(8, 'today'),
            'signalStats' => \MeNews\Services\Signal::stats(),
            'canonical' => absolute_url('/'),
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'WebSite', 'name' => 'ME News Ireland', 'url' => absolute_url('/'), 'publisher' => ['@id' => absolute_url('/#organization')], 'potentialAction' => ['@type' => 'SearchAction', 'target' => absolute_url('/search?q={search_term_string}'), 'query-input' => 'required name=search_term_string']],
                \MeNews\Services\Feeds::organization(),
            ]],
            'bodyClass' => 'page-home' . ($county ? ' has-county' : ''),
        ]));
    }

    public static function category(Request $r, array $p): Response
    {
        $name = Categories::fromSlug($p['slug']);
        if ($name === null) {
            throw new HttpException(404, 'Section not found');
        }
        $page = $r->int('page', 1, 1, 200);
        $per = 24;
        $cutoff = \MeNews\Services\Membership::archiveCutoff(Auth::user());
        $rows = Stories::feed(['category' => $name, 'since' => $cutoff], $per, ($page - 1) * $per);
        $total = Stories::countPublished(['category' => $name, 'since' => $cutoff]);

        $extra = [];
        if ($name === 'Sport') {
            $extra['clubResults'] = \MeNews\Services\Notices::recent(['kind' => 'result'], 6);
        }
        if ($name === 'Community') {
            $since = gmdate('Y-m-d\TH:i:s', time() - 30 * 86400) . '+00:00';
            $extra['leaderboard'] = [
                'counties' => Database::all("SELECT county, COUNT(*) AS n FROM stories WHERE kind='community' AND status='published' AND county<>'' AND COALESCE(published_at,created_at)>? GROUP BY county ORDER BY n DESC LIMIT 10", [$since]),
                'reporters' => Database::all("SELECT u.display_name,u.handle,u.home_county,u.accent,u.reports_published,u.reports_filed,(SELECT COUNT(*) FROM confirmations c JOIN stories s2 ON s2.id=c.story_id WHERE s2.author_user_id=u.id) AS confirmations FROM users u WHERE u.reports_published>0 ORDER BY u.reports_published DESC, confirmations DESC LIMIT 10"),
                'whatsapp' => Database::setting('whatsapp_number'),
            ];
        }
        return View::page('listing', self::base($extra + [
            'title' => $name . ' — ME News Ireland',
            'description' => Categories::ALL[$name]['blurb'],
            'heading' => $name,
            'kicker' => 'Section',
            'blurb' => Categories::ALL[$name]['blurb'] . ($cutoff ? ' Showing the last ' . \MeNews\Services\Membership::archiveDays() . ' days; ME+ members read the full archive.' : ''),
            'icon' => Categories::ALL[$name]['icon'],
            'rows' => $rows,
            'page' => $page,
            'pages' => (int)max(1, ceil($total / $per)),
            'total' => $total,
            'basePath' => '/section/' . $p['slug'],
            'feedLinks' => [['/feed/section/' . $p['slug'] . '.xml', $name]],
            'trending' => Stories::trending(5),
            'ads' => self::ads('', '', 2, 'section'),
            'banner' => self::banner('', '', 'section'),
        ]));
    }

    public static function county(Request $r, array $p): Response
    {
        $county = null;
        foreach (Locations::countyNames() as $c) {
            if (slugify($c) === $p['slug']) {
                $county = $c;
            }
        }
        if ($county === null) {
            throw new HttpException(404, 'County not found');
        }
        $page = $r->int('page', 1, 1, 200);
        $per = 24;
        $rows = Stories::feed(['county' => $county], $per, ($page - 1) * $per);
        $total = Stories::countPublished(['county' => $county]);
        $province = Locations::provinceFor($county);
        $towns = array_values(array_unique(array_column(array_filter(Locations::all(), static fn($t) => $t['county'] === $county), 'town')));
        $week = Database::count("SELECT COUNT(*) FROM stories WHERE status='published' AND county=? AND COALESCE(published_at,created_at)>?", [$county, gmdate('Y-m-d\TH:i:s', time() - 7 * 86400) . '+00:00']);
        $topTowns = Database::all("SELECT location_name, COUNT(*) AS n FROM stories WHERE status='published' AND county=? AND location_name IS NOT NULL AND location_name NOT LIKE 'Co. %' AND COALESCE(published_at,created_at)>? GROUP BY location_name ORDER BY n DESC LIMIT 6", [$county, gmdate('Y-m-d\TH:i:s', time() - 30 * 86400) . '+00:00']);
        $deaths = \MeNews\Services\Notices::recent(['county' => $county, 'kind' => 'death'], 4);
        $events = \MeNews\Services\Notices::recent(['county' => $county, 'kind' => 'event', 'upcoming' => true], 3);
        $warning = \MeNews\Services\Alerts::headline($county);
        $closures = \MeNews\Services\Alerts::closures($county, 1);
        $intro = 'County ' . $county . ' news from ME News Ireland: ' . ($week ? $week . ' stories this week' : 'the latest stories') . ' across ' . count($towns) . ' towns and villages in ' . ($province ?: 'Ireland')
            . ($topTowns ? ', busiest lately in ' . implode(', ', array_map(static fn($t) => $t['location_name'], array_slice($topTowns, 0, 3))) : '')
            . '. Alongside the wire from RTÉ, the Irish Times, TheJournal and the local press, this page carries what nobody else publishes for ' . possessive($county) . ' communities: death notices and funeral arrangements, school closures and Met Éireann warnings, club results, planning notices and reports from neighbours on the ground.';
        return View::page('listing', self::base([
            'title' => $county . ' news today: local stories, deaths, school closures & alerts — ME News Ireland',
            'description' => 'Local news for County ' . $county . ' updated all day, plus death notices, school closures, weather warnings, planning notices, club results and community reports from ' . implode(', ', array_slice($towns, 0, 4)) . ' and every town in the county.',
            'canonical' => absolute_url('/county/' . $p['slug'] . ($page > 1 ? '?page=' . $page : '')),
            'heading' => 'Co. ' . $county,
            'kicker' => ($province ?: 'County') . ' · ' . count($towns) . ' towns',
            'blurb' => $intro,
            'icon' => 'pin',
            'rows' => $rows,
            'page' => $page,
            'pages' => (int)max(1, ceil($total / $per)),
            'total' => $total,
            'basePath' => '/county/' . $p['slug'],
            'trending' => Stories::trending(5),
            'ads' => self::ads($county, '', 2, 'county'),
            'banner' => self::banner($county, '', 'county'),
            'county' => $county,
            'countyStrip' => ['deaths' => $deaths, 'events' => $events, 'warning' => $warning, 'closures' => $closures, 'towns' => $topTowns, 'slug' => $p['slug'], 'whatsapp' => Database::setting('whatsapp_channel_' . $p['slug'])],
            'feedLinks' => [['/feed/county/' . $p['slug'] . '.xml', 'Co. ' . $county]],
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'County ' . $county . ' news', 'url' => absolute_url('/county/' . $p['slug']), 'isPartOf' => ['@type' => 'WebSite', 'name' => 'ME News Ireland', 'url' => absolute_url('/')], 'about' => ['@type' => 'AdministrativeArea', 'name' => 'County ' . $county, 'containedInPlace' => ['@type' => 'Country', 'name' => 'Ireland']]],
        ]));
    }

    /** RFC 9116 contact for security researchers. */
    public static function securityTxt(Request $r): Response
    {
        $contact = Database::setting('security_contact') ?: Database::setting('contact_email') ?: \MeNews\Config::get('MAIL_FROM', 'security@menews.ie');
        $lines = [
            'Contact: mailto:' . $contact,
            'Expires: ' . gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 year')),
            'Preferred-Languages: en, ga',
            'Canonical: ' . absolute_url('/.well-known/security.txt'),
            'Policy: ' . absolute_url('/privacy'),
        ];
        return Response::text(implode("\n", $lines) . "\n");
    }

    public static function search(Request $r): Response
    {
        $q = $r->query('q', '', 120);
        $cutoff = \MeNews\Services\Membership::archiveCutoff(Auth::user());
        $rows = $q !== '' ? Stories::feed(['q' => $q, 'since' => $cutoff], 40) : [];
        return View::page('listing', self::base([
            'title' => ($q !== '' ? '“' . $q . '” — ' : '') . 'Search — ME News Ireland',
            'description' => 'Search every published story on ME News Ireland.',
            'heading' => $q !== '' ? '“' . $q . '”' : 'Search',
            'kicker' => 'Search',
            'blurb' => $q !== '' ? count($rows) . ' result' . (count($rows) === 1 ? '' : 's') . ' across the wire and community desk' . ($cutoff ? ' from the last ' . \MeNews\Services\Membership::archiveDays() . ' days — ME+ members search the full archive' : '') : 'Search stories, towns and counties.',
            'icon' => 'search',
            'rows' => $rows,
            'page' => 1,
            'pages' => 1,
            'total' => count($rows),
            'basePath' => '/search',
            'q' => $q,
            'trending' => Stories::trending(5),
            'ads' => self::ads(),
        ]));
    }

    public static function story(Request $r, array $p): Response
    {
        $story = Stories::bySlug($p['slug']);
        if (!$story) {
            throw new HttpException(404, 'Story not found');
        }
        Database::query('UPDATE stories SET views=views+1 WHERE id=?', [$story['id']]);
        $story['views']++;
        $author = $story['author_user_id'] ? Database::one('SELECT id,display_name,handle,title,desk,bio,accent,is_verified,reputation,home_town,home_county,role,reports_published,plan FROM users WHERE id=?', [$story['author_user_id']]) : null;
        $isWire = $story['kind'] === 'wire';
        // Members' archive: community reports older than the archive window are summarised for non-members.
        $archived = !$isWire && \MeNews\Services\Membership::isArchived($story, Auth::user());
        return View::page('story', self::base([
            // Wire pages canonicalise to the publisher and stay out of the index: the original is the article.
            'canonical' => $isWire ? $story['source_url'] : absolute_url($story['url']),
            'robots' => $isWire ? 'noindex,follow' : null,
            'cluster' => \MeNews\Services\Clusters::get($story['cluster_id'] ?? null),
            'members' => \MeNews\Services\Clusters::members($story['cluster_id'] ?? null, $story['id']),
            'title' => $story['title'] . ' — ME News Ireland',
            'description' => excerpt($story['summary'] ?: $story['body'], 200),
            'ogImage' => $story['image'],
            'story' => $story,
            'author' => $author,
            'archived' => $archived,
            'feedLinks' => [['/feed/section/' . Categories::slug((string)$story['category']) . '.xml', $story['category']], $story['county'] ? ['/feed/county/' . slugify($story['county']) . '.xml', 'Co. ' . $story['county']] : null],
            'robots' => $isWire ? 'noindex,follow' : ($archived ? 'noindex,follow' : null),
            'comments' => Stories::comments($story['id']),
            'confirmations' => Stories::confirmations($story['id']),
            'related' => Stories::related($story, 4),
            'more' => Stories::feed(['exclude' => [$story['id']]], 5),
            'signal' => \MeNews\Services\Signal::tally($story['id']) + ['mine' => \MeNews\Services\Signal::myVote($story['id'], Auth::user())],
            'signalRank' => (static function () use ($story) { foreach (\MeNews\Services\Signal::leaderboard('today', 40) as $s) { if ($s['id'] === $story['id']) { return $s['signal_rank']; } } return null; })(),
            'ads' => self::ads($story['county'] ?? '', (string)($story['location_name'] ?? ''), 2, 'story'),
            'banner' => self::banner($story['county'] ?? '', (string)($story['location_name'] ?? ''), 'story'),
            'bodyClass' => 'page-story',
        ]));
    }

    public static function near(Request $r): Response
    {
        $locality = \MeNews\Support\Visitor::locality(24);
        return View::page('near', self::base([
            'title' => ($locality['mode'] === 'none' ? 'Near me' : $locality['title']) . ' — ME News Ireland',
            'description' => 'Local stories for wherever you are in Ireland, chosen by your location.',
            'locality' => $locality,
            'counties' => Locations::countyNames(),
            'mapPoints' => $locality['position'] ? array_values(array_filter($locality['stories'], static fn($s) => $s['latitude'] !== null)) : [],
            'bodyClass' => 'page-near',
        ]));
    }

    public static function map(Request $r): Response
    {
        return View::page('map', self::base([
            'title' => 'Live map — ME News Ireland',
            'description' => 'Every published story pinned across the island, from the wire and the community desk.',
            'points' => Stories::mapPoints(300),
            'counties' => Stories::countyPoints(),
            'colours' => \MeNews\Support\Geo::COLOURS,
            'bodyClass' => 'page-map',
        ]));
    }

    /** A county's own permanent map page (an SEO asset as much as a product). */
    public static function countyMap(Request $r, array $p): Response
    {
        $county = null;
        foreach (Locations::countyNames() as $c) {
            if (slugify($c) === $p['slug']) {
                $county = $c;
            }
        }
        if ($county === null) {
            throw new HttpException(404, 'County not found');
        }
        $points = array_values(array_filter(Stories::mapPoints(400), static fn($pt) => $pt['county'] === $county));
        $centre = \MeNews\Support\Geo::county($county);
        return View::page('map', self::base([
            'title' => $county . ' news map: every story in County ' . $county . ', placed — ME News Ireland',
            'description' => 'A live map of County ' . $county . ': wire stories by town, community reports where they were filed, filtered by section and time.',
            'points' => $points,
            'counties' => array_values(array_filter(Stories::countyPoints(), static fn($cp) => $cp['county'] === $county)),
            'colours' => \MeNews\Support\Geo::COLOURS,
            'focus' => $centre ? ['lat' => $centre[0], 'lng' => $centre[1]] : null,
            'focusCounty' => $county,
            'canonical' => absolute_url('/county/' . $p['slug'] . '/map'),
            'bodyClass' => 'page-map',
        ]));
    }

    public static function contributors(): array
    {
        $rows = Database::all("SELECT id,display_name,handle,title,desk,bio,accent,home_town,home_county,reputation,is_verified,created_at FROM users WHERE role='contributor' ORDER BY created_at");
        foreach ($rows as &$c) {
            $c['stories'] = Database::count("SELECT COUNT(*) FROM stories WHERE author_user_id=? AND status='published'", [$c['id']]);
            $c['curated'] = Database::count("SELECT COUNT(*) FROM stories WHERE author_user_id=? AND status='published' AND kind='wire'", [$c['id']]);
            $c['reported'] = $c['stories'] - $c['curated'];
            $c['latest'] = Database::value("SELECT MAX(COALESCE(published_at,created_at)) FROM stories WHERE author_user_id=? AND status='published'", [$c['id']]) ?: null;
        }
        return $rows;
    }

    public static function contributorsPage(Request $r): Response
    {
        return View::page('contributors', self::base([
            'title' => 'Contributors — ME News Ireland',
            'description' => 'Meet the ME News Ireland editorial team.',
            'contributors' => self::contributors(),
        ]));
    }

    public static function contributor(Request $r, array $p): Response
    {
        $c = Database::one("SELECT id,display_name,handle,title,desk,bio,accent,home_town,home_county,reputation,is_verified,created_at FROM users WHERE handle=? AND role IN ('contributor','editor','admin')", [$p['handle']]);
        if (!$c) {
            throw new HttpException(404, 'Contributor not found');
        }
        $page = $r->int('page', 1, 1, 200);
        $per = 18;
        $rows = Stories::feed(['author' => $c['id']], $per, ($page - 1) * $per);
        $total = Database::count("SELECT COUNT(*) FROM stories WHERE author_user_id=? AND status='published'", [$c['id']]);
        $c['curated'] = Database::count("SELECT COUNT(*) FROM stories WHERE author_user_id=? AND status='published' AND kind='wire'", [$c['id']]);
        $c['reported'] = $total - $c['curated'];
        return View::page('contributor', self::base([
            'title' => $c['display_name'] . ' — ME News Ireland',
            'description' => $c['title'] . '. ' . excerpt($c['bio'], 150),
            'contributor' => $c,
            'rows' => $rows,
            'page' => $page,
            'pages' => (int)max(1, ceil($total / $per)),
            'total' => $total,
            'basePath' => '/contributors/' . $c['handle'],
        ]));
    }

    public static function about(Request $r): Response
    {
        return View::page('about', self::base([
            'title' => 'How ME News works — ME News Ireland',
            'description' => 'The ME Trust Engine, editorial labels, our sources and how community reporting is screened.',
            'sources' => NewsWire::sources(),
            'runs' => Database::installed() ? Database::all('SELECT * FROM wire_runs ORDER BY id DESC LIMIT 16') : [],
            'contributors' => self::contributors(),
            'pulse' => Stories::pulse(),
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [
                ['@type' => 'Question', 'name' => 'What does the Wire label mean?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A headline from an established Irish publisher, curated by our desk and linked to the original. ME has not independently checked it.']],
                ['@type' => 'Question', 'name' => 'What does Verified mean on ME News?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'An ME editor contacted the reporter, examined the media and location, and the facts stood up. Never applied to wire headlines.']],
                ['@type' => 'Question', 'name' => 'What is a Corroborated report?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A community report that three separate people have independently confirmed with “I saw this too”.']],
                ['@type' => 'Question', 'name' => 'Do I need an account to report something?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'No. A first report needs only a name and an email or mobile number, confirmed by a one-tap link; an editor reads every report before it appears.']],
            ]],
        ]));
    }

    public static function plus(Request $r): Response
    {
        return View::page('plus', self::base([
            'title' => 'ME+ membership — ME News Ireland',
            'description' => 'Follow up to ten local areas, receive local alerts first and support independent Irish community journalism.',
            'priceLabel' => \MeNews\Services\Membership::priceLabel(),
            'annualLabel' => \MeNews\Services\Membership::annualLabel(),
            'benefits' => \MeNews\Services\Membership::benefits(),
            'isPlus' => \MeNews\Services\Membership::isPlus(Auth::user()),
            'archiveDays' => \MeNews\Services\Membership::archiveDays(),
            'stripe' => \MeNews\Services\Stripe::configured(),
            'memberCount' => Database::count("SELECT COUNT(*) FROM users WHERE plan='ME+'"),
            'bodyClass' => 'page-plus',
        ]));
    }

    public static function dashboard(Request $r): Response
    {
        if (!Auth::user()) {
            return Response::redirect('/?auth=signin&next=/dashboard');
        }
        return View::page('dashboard', self::base([
            'title' => 'My dashboard — ME News Ireland',
            'description' => 'Your reports, followed areas, membership and notifications.',
            'counties' => Locations::countyNames(),
            'categories' => Categories::community(),
            'bodyClass' => 'page-app',
        ]));
    }

    public static function newsroom(Request $r): Response
    {
        return View::page('newsroom', self::base([
            'title' => 'Newsroom — ME News Ireland',
            'description' => 'Editorial review queue and administration.',
            'labels' => Categories::LABELS,
            'categories' => Categories::names(),
            'counties' => Locations::countyNames(),
            'bodyClass' => 'page-app page-newsroom',
        ]));
    }

    public static function media(Request $r, array $p): Response
    {
        $row = Database::one("SELECT media_public FROM stories WHERE id=? AND status='published'", [$p['id']]);
        if (!$row || !$row['media_public']) {
            throw new HttpException(404, 'Media not found');
        }
        try {
            $file = Media::path($row['media_public'], 'public');
        } catch (\Throwable) {
            throw new HttpException(404, 'Media not found');
        }
        Media::stream($file);
    }

    /** Quarantined media via a signed, short-lived URL (for reverse image search tools). */
    public static function quarantineMedia(Request $r, array $p): Response
    {
        if (!Media::verifySignature($p['id'], $p['sig'])) {
            throw new HttpException(404, 'Media not found');
        }
        $row = Database::one('SELECT media_original FROM stories WHERE id=?', [$p['id']]);
        if (!$row || !$row['media_original']) {
            throw new HttpException(404, 'Media not found');
        }
        Media::stream(Media::path($row['media_original'], 'quarantine'));
    }

    public static function sitemap(Request $r): Response
    {
        $urls = ['/', '/near', '/signal', '/map', '/advertise', '/kids', '/kids/crossword', '/kids/wordsearch', '/kids/quiz', '/kids/county-game', '/contributors', '/about', '/plus', '/feeds'];
        foreach (Categories::ALL as $meta) {
            $urls[] = '/section/' . $meta['slug'];
        }
        foreach (Locations::countyNames() as $c) {
            $urls[] = '/county/' . slugify($c);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . e(absolute_url($u)) . '</loc></url>';
        }
        // Only our own pages are indexed: county, section, utility and community pages. Wire headlines belong to their publishers.
        $urls[] = '/notices';
        $urls[] = '/alerts';
        $urls[] = '/poll';
        $urls[] = '/corrections';
        $urls[] = '/ownership';
        $urls[] = '/privacy';
        $urls[] = '/moderation';
        foreach (\MeNews\Services\Notices::KINDS as $k => $meta) {
            $urls[] = '/notices/' . $k;
        }
        foreach (Locations::countyNames() as $c) {
            $urls[] = '/county/' . slugify($c) . '/map';
            $urls[] = '/notices?county=' . rawurlencode($c);
            $urls[] = '/alerts?county=' . rawurlencode($c);
        }
        foreach (Database::all("SELECT kind, slug, COALESCE(updated_at,created_at) AS u FROM notices WHERE status='published' ORDER BY published_at DESC LIMIT 2000") as $n) {
            $xml .= '<url><loc>' . e(absolute_url('/notices/' . $n['kind'] . '/' . $n['slug'])) . '</loc><lastmod>' . e(substr((string)$n['u'], 0, 10)) . '</lastmod></url>';
        }
        foreach (Database::all("SELECT slug, COALESCE(updated_at,created_at) AS u FROM stories WHERE status='published' AND kind='community' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 2000") as $s) {
            $xml .= '<url><loc>' . e(absolute_url('/story/' . $s['slug'])) . '</loc><lastmod>' . e(substr((string)$s['u'], 0, 10)) . '</lastmod></url>';
        }
        return Response::text($xml . '</urlset>', 'application/xml; charset=utf-8');
    }

    /** RSS / Atom / JSON Feed for the whole site, one section, one county or original reporting only. */
    public static function rss(Request $r, array $p = []): Response
    {
        $key = isset($p['group']) ? $p['group'] . ':' . $p['slug'] : ($p['key'] ?? 'all');
        $feed = \MeNews\Services\Feeds::resolve($key);
        if (!$feed) {
            throw new HttpException(404, 'Feed not found');
        }
        $fmt = $p['fmt'] ?? (str_ends_with($r->path, '.atom') ? 'atom' : (str_ends_with($r->path, '.json') ? 'json' : 'xml'));
        $limit = $r->int('limit', 50, 10, 100);
        $res = match ($fmt) {
            'atom' => Response::text(\MeNews\Services\Feeds::atom($feed, $limit), 'application/atom+xml; charset=utf-8'),
            'json' => Response::json(\MeNews\Services\Feeds::json($feed, $limit)),
            default => Response::text(\MeNews\Services\Feeds::rss($feed, $limit), 'application/rss+xml; charset=utf-8'),
        };
        return $res->withHeader('Cache-Control', 'public, max-age=300')->withHeader('Access-Control-Allow-Origin', '*');
    }

    public static function newsSitemap(Request $r): Response
    {
        return Response::text(\MeNews\Services\Feeds::newsSitemap(), 'application/xml; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=300');
    }

    public static function robots(Request $r): Response
    {
        return Response::text(\MeNews\Services\Feeds::robots());
    }

    public static function opensearch(Request $r): Response
    {
        return Response::text(\MeNews\Services\Feeds::opensearch(), 'application/opensearchdescription+xml; charset=utf-8');
    }

    /** Human-readable directory of every feed for aggregators and readers. */
    public static function feedsPage(Request $r): Response
    {
        return View::page('feeds', self::base([
            'title' => 'Feeds & syndication — ME News Ireland',
            'description' => 'RSS, Atom and JSON feeds for every section and county, plus the Google News sitemap and original-reporting feed.',
            'feeds' => \MeNews\Services\Feeds::catalogue(),
        ]));
    }

    public static function savedRedirect(Request $r): Response
    {
        return Response::redirect(Auth::user() ? '/dashboard#saved' : '/?auth=signin&next=/dashboard%23saved');
    }

    private static function ads(string $county = '', string $town = '', int $limit = 2, string $page = 'other'): array
    {
        if (\MeNews\Services\Membership::isPlus(Auth::user())) {
            return [];
        }
        return \MeNews\Services\Ads::pick('sidebar', $county, $town, $limit, [], $page);
    }

    private static function banner(string $county = '', string $town = '', string $page = 'other'): ?array
    {
        if (\MeNews\Services\Membership::isPlus(Auth::user())) {
            return null;
        }
        $rows = \MeNews\Services\Ads::pick('banner', $county, $town, 1, [], $page);
        return $rows[0] ?? null;
    }
}
