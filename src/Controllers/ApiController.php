<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\NewsWire;
use MeNews\Services\Stripe;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Locations;

/** Public JSON API. */
final class ApiController
{
    public static function health(Request $r): Response
    {
        Database::query('SELECT 1');
        return Response::json([
            'ok' => true,
            'version' => ME_VERSION,
            'app_env' => Config::get('APP_ENV', 'production'),
            'openai_configured' => Config::get('OPENAI_API_KEY') !== '',
            'stripe_configured' => Stripe::configured(),
            'auto_publish_safe' => Config::bool('AUTO_PUBLISH_SAFE'),
            'location_source' => Locations::source(),
            'wire' => ['enabled' => NewsWire::enabled(), 'last_refresh' => NewsWire::lastRefresh(), 'stale' => NewsWire::isStale()],
            'stories_published' => Stories::countPublished(),
            'time' => now(),
        ]);
    }

    public static function counties(Request $r): Response
    {
        return Response::json(Locations::counties());
    }

    public static function locations(Request $r): Response
    {
        return Response::json(Locations::search($r->query('q'), $r->query('county'), $r->int('limit', 80, 1, 300)));
    }

    public static function categories(Request $r): Response
    {
        $counts = Stories::categoryCounts();
        $out = [];
        foreach (Categories::ALL as $name => $meta) {
            $out[] = ['name' => $name, 'slug' => $meta['slug'], 'blurb' => $meta['blurb'], 'count' => (int)($counts[$name] ?? 0)];
        }
        return Response::json($out);
    }

    public static function feed(Request $r): Response
    {
        $filters = [
            'q' => $r->query('q', '', 120),
            'category' => $r->query('category', '', 40),
            'county' => $r->query('county', '', 60),
            'location' => $r->query('location', '', 100),
            'kind' => in_array($r->query('kind'), ['wire', 'community'], true) ? $r->query('kind') : '',
        ];
        $limit = $r->int('limit', 24, 1, 100);
        $page = $r->int('page', 1, 1, 500);
        $rows = Stories::feed($filters, $limit, ($page - 1) * $limit);
        return Response::json(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => Stories::countPublished($filters)]);
    }

    public static function story(Request $r, array $p): Response
    {
        $story = Stories::bySlug($p['key']) ?? Stories::byId($p['key']);
        if (!$story) {
            throw new HttpException(404, 'Story not found');
        }
        $story['comments'] = Stories::comments($story['id']);
        $story['confirmations'] = Stories::confirmations($story['id']);
        $story['related'] = Stories::related($story, 4);
        return Response::json($story);
    }

    public static function map(Request $r): Response
    {
        $category = $r->query('category', '', 40);
        $kind = in_array($r->query('kind'), ['wire', 'community'], true) ? $r->query('kind') : '';
        return Response::json([
            'points' => Stories::mapPoints($r->int('limit', 200, 1, 400), $category, $kind),
            'counties' => Stories::countyPoints(),
            'colours' => \MeNews\Support\Geo::COLOURS,
            'generated_at' => now(),
        ]);
    }

    /**
     * Stories near a position. GET /api/near?lat=..&lng=..[&radius=40][&limit=12]
     * Also returns the rendered "Near you" section so the page can drop it in without a reload.
     */
    public static function near(Request $r): Response
    {
        $lat = $r->query('lat', '', 20);
        $lng = $r->query('lng', '', 20);
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
            throw new HttpException(400, 'Send lat and lng');
        }
        $lat = (float)$lat;
        $lng = (float)$lng;
        $place = \MeNews\Support\Geo::nearest($lat, $lng);
        $radius = $r->int('radius', 40, 5, 200);
        $limit = $r->int('limit', 12, 1, 40);
        $stories = $place['in_ireland'] ? Stories::near($lat, $lng, $radius, $limit, $place['county']) : [];
        if ($place['in_ireland'] && count($stories) < 4 && $radius < 80) {
            $radius = 80;
            $stories = Stories::near($lat, $lng, $radius, $limit, $place['county']);
        }
        $title = $place['in_ireland'] ? ($place['town'] ? $place['town'] . ', Co. ' . $place['county'] : 'Co. ' . $place['county']) : 'Outside Ireland';
        $locality = ['mode' => $place['in_ireland'] ? 'gps' : 'abroad', 'place' => $place, 'stories' => $stories, 'radius' => $radius, 'county' => $place['county'], 'title' => $title, 'position' => ['lat' => $lat, 'lng' => $lng]];
        return Response::json([
            'place' => $place, 'title' => $title, 'radius' => $radius, 'items' => $stories,
            'county_url' => $place['county'] ? '/county/' . slugify($place['county']) : null,
            'html' => \MeNews\View::partial('partials/near', ['locality' => $locality, 'counties' => Locations::countyNames(), 'compact' => (bool)$r->query('compact')]),
        ]);
    }

    /**
     * Cron / uptime-monitor endpoint: GET /cron/wire?key=CRON_KEY[&force=1].
     * Refreshes the wire when stale (or always with force=1). Safe to call as often as you like.
     */
    public static function cronWire(Request $r): Response
    {
        $key = Config::get('CRON_KEY');
        if ($key === '' || !hash_equals($key, $r->query('key', '', 200))) {
            throw new HttpException(403, 'Invalid cron key');
        }
        set_time_limit(280);
        $force = $r->query('force') === '1';
        $summary = NewsWire::refresh($force);
        $summary['last_refresh'] = NewsWire::lastRefresh();
        $summary['stories_published'] = Stories::countPublished();
        return Response::json($summary);
    }

    public static function pulse(Request $r): Response
    {
        return Response::json(['hours' => Stories::pulse(), 'counties' => Stories::countyActivity(8), 'total' => Stories::countPublished(), 'wire_last_refresh' => NewsWire::lastRefresh()]);
    }

    public static function ads(Request $r): Response
    {
        $sql = "SELECT id,business_name,title,body,url,target_county,target_town FROM ads WHERE status='approved'";
        $args = [];
        foreach (['county' => 'target_county', 'town' => 'target_town'] as $param => $field) {
            if ($v = $r->query($param, '', 80)) {
                $sql .= " AND ({$field} IS NULL OR {$field}='' OR {$field}=?)";
                $args[] = $v;
            }
        }
        $rows = Database::all($sql . ' ORDER BY created_at DESC LIMIT ' . $r->int('limit', 3, 1, 10), $args);
        foreach ($rows as &$ad) {
            Database::query('UPDATE ads SET impressions=impressions+1 WHERE id=?', [$ad['id']]);
            if (!preg_match('~^https?://~i', (string)($ad['url'] ?? ''))) {
                $ad['url'] = '';
            }
        }
        return Response::json($rows);
    }

    public static function adClick(Request $r, array $p): Response
    {
        $ad = Database::one("SELECT url FROM ads WHERE id=? AND status='approved'", [$p['id']]);
        if (!$ad || !preg_match('~^https?://~i', (string)$ad['url'])) {
            throw new HttpException(404, 'Advert not found');
        }
        Database::query('UPDATE ads SET clicks=clicks+1 WHERE id=?', [$p['id']]);
        return Response::redirect($ad['url']);
    }

    /** Refresh the wire if it is stale. Safe to call from the browser; a lock prevents overlap. */
    public static function wireRefresh(Request $r): Response
    {
        if (!NewsWire::enabled()) {
            return Response::json(['ran' => false, 'reason' => 'disabled']);
        }
        if (!NewsWire::isStale()) {
            return Response::json(['ran' => false, 'reason' => 'fresh', 'last_refresh' => NewsWire::lastRefresh()]);
        }
        set_time_limit(180);
        $summary = NewsWire::refresh(false);
        $summary['last_refresh'] = NewsWire::lastRefresh();
        return Response::json($summary);
    }

    public static function wireStatus(Request $r): Response
    {
        return Response::json([
            'enabled' => NewsWire::enabled(),
            'last_refresh' => NewsWire::lastRefresh(),
            'stale' => NewsWire::isStale(),
            'latest_story' => Database::value("SELECT MAX(published_at) FROM stories WHERE kind='wire' AND status='published'") ?: null,
            'runs' => Database::all('SELECT source_key,started_at,finished_at,fetched,inserted,error FROM wire_runs ORDER BY id DESC LIMIT 16'),
        ]);
    }
}
