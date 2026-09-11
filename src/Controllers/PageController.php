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
        $hero = Stories::hero();
        $exclude = $hero ? [$hero['id']] : [];
        $latest = Stories::feed(['exclude' => $exclude], 9);
        $exclude = array_merge($exclude, array_column($latest, 'id'));
        $sections = [];
        foreach (['National', 'Local', 'Sport', 'Business', 'Culture'] as $cat) {
            $rows = Stories::feed(['category' => $cat, 'exclude' => $exclude], 4);
            if ($rows) {
                $sections[$cat] = $rows;
                $exclude = array_merge($exclude, array_column($rows, 'id'));
            }
        }
        $community = Stories::feed(['kind' => 'community'], 4);
        $contributors = self::contributors();
        return View::page('home', self::base([
            'title' => 'ME News Ireland — Your Community. Your News. Live.',
            'description' => 'Ireland-wide news, county-by-county reporting and community stories, screened by the ME Trust Engine and labelled by human editors.',
            'hero' => $hero,
            'latest' => $latest,
            'sections' => $sections,
            'community' => $community,
            'trending' => Stories::trending(6),
            'pulse' => Stories::pulse(),
            'counties' => Stories::countyActivity(8),
            'contributors' => $contributors,
            'counts' => Stories::categoryCounts(),
            'mapPoints' => Stories::mapPoints(120),
            'mapCounties' => Stories::countyPoints(),
            'ads' => self::ads((string)(\MeNews\Support\Visitor::locality(1)['county'] ?? '')),
            'banner' => self::banner((string)(\MeNews\Support\Visitor::locality(1)['county'] ?? '')),
            'totalPublished' => Stories::countPublished(),
            'weather' => \MeNews\Services\Weather::today(),
            'edition' => \MeNews\Support\Daily::edition(),
            'longDate' => \MeNews\Support\Daily::longDate(),
            'focal' => \MeNews\Support\Daily::focal(),
            'greeting' => \MeNews\Support\Daily::greeting(),
            'crossword' => \MeNews\Services\Puzzles::crossword(\MeNews\Support\Daily::date(), 'junior'),
            'quiz' => \MeNews\Services\Puzzles::quiz(\MeNews\Support\Daily::date()),
            'youngReaders' => \MeNews\Controllers\KidsController::youngReaders(3),
            'locality' => \MeNews\Support\Visitor::locality(8),
            'countyNames' => Locations::countyNames(),
            'signalBoard' => \MeNews\Services\Signal::featured(8, 'today'),
            'signalStats' => \MeNews\Services\Signal::stats(),
            'bodyClass' => 'page-home',
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
        $rows = Stories::feed(['category' => $name], $per, ($page - 1) * $per);
        $total = Stories::countPublished(['category' => $name]);
        return View::page('listing', self::base([
            'title' => $name . ' — ME News Ireland',
            'description' => Categories::ALL[$name]['blurb'],
            'heading' => $name,
            'kicker' => 'Section',
            'blurb' => Categories::ALL[$name]['blurb'],
            'icon' => Categories::ALL[$name]['icon'],
            'rows' => $rows,
            'page' => $page,
            'pages' => (int)max(1, ceil($total / $per)),
            'total' => $total,
            'basePath' => '/section/' . $p['slug'],
            'trending' => Stories::trending(5),
            'ads' => self::ads(),
            'banner' => self::banner(),
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
        return View::page('listing', self::base([
            'title' => 'Co. ' . $county . ' news — ME News Ireland',
            'description' => 'The latest news and community reports from County ' . $county . '.',
            'heading' => 'Co. ' . $county,
            'kicker' => Locations::provinceFor($county) ?: 'County',
            'blurb' => 'Everything published from County ' . $county . ', newest first.',
            'icon' => '◎',
            'rows' => $rows,
            'page' => $page,
            'pages' => (int)max(1, ceil($total / $per)),
            'total' => $total,
            'basePath' => '/county/' . $p['slug'],
            'trending' => Stories::trending(5),
            'ads' => self::ads($county),
            'banner' => self::banner($county),
        ]));
    }

    public static function search(Request $r): Response
    {
        $q = $r->query('q', '', 120);
        $rows = $q !== '' ? Stories::feed(['q' => $q], 40) : [];
        return View::page('listing', self::base([
            'title' => ($q !== '' ? '“' . $q . '” — ' : '') . 'Search — ME News Ireland',
            'description' => 'Search every published story on ME News Ireland.',
            'heading' => $q !== '' ? '“' . $q . '”' : 'Search',
            'kicker' => 'Search',
            'blurb' => $q !== '' ? count($rows) . ' result' . (count($rows) === 1 ? '' : 's') . ' across the wire and community desk' : 'Search stories, towns and counties.',
            'icon' => '⌕',
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
        $author = $story['author_user_id'] ? Database::one('SELECT id,display_name,handle,title,desk,bio,accent,is_verified,reputation,home_town,home_county,role FROM users WHERE id=?', [$story['author_user_id']]) : null;
        return View::page('story', self::base([
            'title' => $story['title'] . ' — ME News Ireland',
            'description' => excerpt($story['summary'] ?: $story['body'], 200),
            'ogImage' => $story['image'],
            'story' => $story,
            'author' => $author,
            'comments' => Stories::comments($story['id']),
            'confirmations' => Stories::confirmations($story['id']),
            'related' => Stories::related($story, 4),
            'more' => Stories::feed(['exclude' => [$story['id']]], 5),
            'signal' => \MeNews\Services\Signal::tally($story['id']) + ['mine' => \MeNews\Services\Signal::myVote($story['id'], Auth::user())],
            'signalRank' => (static function () use ($story) { foreach (\MeNews\Services\Signal::leaderboard('today', 40) as $s) { if ($s['id'] === $story['id']) { return $s['signal_rank']; } } return null; })(),
            'ads' => self::ads($story['county'] ?? '', (string)($story['location_name'] ?? '')),
            'banner' => self::banner($story['county'] ?? '', (string)($story['location_name'] ?? '')),
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

    public static function contributors(): array
    {
        $rows = Database::all("SELECT id,display_name,handle,title,desk,bio,accent,home_town,home_county,reputation,is_verified,created_at FROM users WHERE role='contributor' ORDER BY created_at");
        foreach ($rows as &$c) {
            $c['stories'] = Database::count("SELECT COUNT(*) FROM stories WHERE author_user_id=? AND status='published'", [$c['id']]);
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
        ]));
    }

    public static function plus(Request $r): Response
    {
        return View::page('plus', self::base([
            'title' => 'ME+ membership — ME News Ireland',
            'description' => 'Follow up to ten local areas, receive local alerts first and support independent Irish community journalism.',
            'priceLabel' => \MeNews\Config::get('ME_PLUS_PRICE_LABEL', '€6.99/month'),
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

    public static function sitemap(Request $r): Response
    {
        $urls = ['/', '/near', '/signal', '/map', '/advertise', '/kids', '/kids/crossword', '/kids/wordsearch', '/kids/quiz', '/kids/county-game', '/contributors', '/about', '/plus'];
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
        foreach (Database::all("SELECT slug, COALESCE(updated_at,created_at) AS u FROM stories WHERE status='published' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 2000") as $s) {
            $xml .= '<url><loc>' . e(absolute_url('/story/' . $s['slug'])) . '</loc><lastmod>' . e(substr((string)$s['u'], 0, 10)) . '</lastmod></url>';
        }
        return Response::text($xml . '</urlset>', 'application/xml; charset=utf-8');
    }

    public static function rss(Request $r): Response
    {
        $rows = Stories::feed([], 40);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>ME News Ireland</title><link>' . e(absolute_url('/')) . '</link><description>Your Community. Your News. Live.</description>';
        foreach ($rows as $s) {
            $xml .= '<item><title>' . e($s['title']) . '</title><link>' . e(absolute_url($s['url'])) . '</link><guid>' . e(absolute_url($s['url'])) . '</guid><description>' . e($s['summary'] ?: excerpt($s['body'], 240)) . '</description><pubDate>' . e(gmdate('D, d M Y H:i:s', strtotime($s['time']) ?: time())) . ' GMT</pubDate><category>' . e($s['category']) . '</category></item>';
        }
        return Response::text($xml . '</channel></rss>', 'application/rss+xml; charset=utf-8');
    }

    private static function ads(string $county = '', string $town = '', int $limit = 2): array
    {
        return \MeNews\Services\Ads::pick('sidebar', $county, $town, $limit);
    }

    private static function banner(string $county = '', string $town = ''): ?array
    {
        $rows = \MeNews\Services\Ads::pick('banner', $county, $town, 1);
        return $rows[0] ?? null;
    }
}
