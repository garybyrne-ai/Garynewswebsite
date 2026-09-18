<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Alerts;
use MeNews\Services\Digest;
use MeNews\Services\Notices;
use MeNews\Services\Polls;
use MeNews\Support\Categories;
use MeNews\Support\Locations;
use MeNews\Support\Visitor;
use MeNews\View;

/** The local layer: notices, alerts & closures, the weekly poll, the daily digest. */
final class LocalController
{
    private static function base(array $extra = []): array
    {
        return ['user' => Auth::user(), 'nav' => Categories::NAV, 'wireLast' => \MeNews\Services\NewsWire::lastRefresh()] + $extra;
    }

    private static function county(Request $r): ?string
    {
        $q = $r->query('county', '', 40);
        foreach (Locations::countyNames() as $c) {
            if (strcasecmp($c, $q) === 0) {
                return $c;
            }
        }
        return $q === '' ? Visitor::county() : null;
    }

    // ------------------------------------------------------------------ notices

    public static function notices(Request $r, array $p = []): Response
    {
        $kind = $p['kind'] ?? '';
        if ($kind !== '' && !Notices::kind($kind)) {
            throw new HttpException(404, 'Notice type not found');
        }
        $county = self::county($r);
        $q = $r->query('q', '', 80);
        $page = $r->int('page', 1, 1, 200);
        $per = 24;
        $filters = ['kind' => $kind, 'county' => $county, 'q' => $q, 'upcoming' => $kind === 'event'];
        $rows = Notices::recent($filters, $per, ($page - 1) * $per);
        $total = Notices::count($filters);
        $meta = $kind ? Notices::KINDS[$kind] : null;
        $where = $county ? 'Co. ' . $county : 'Ireland';
        return View::page('notices', self::base([
            'title' => ($meta ? $meta['plural'] : 'Deaths & notices') . ' · ' . $where . ' — ME News Ireland',
            'description' => $meta ? $meta['blurb'] . ' ' . $where . '.' : 'Death notices, in memoriam, events, local jobs, planning notices, lost pets and club results for ' . $where . ', updated all day.',
            'kind' => $kind, 'meta' => $meta, 'county' => $county, 'q' => $q,
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int)max(1, ceil($total / $per)),
            'counts' => Notices::countsByKind($county), 'counties' => Locations::countyNames(),
            'bodyClass' => 'page-notices',
        ]));
    }

    public static function notice(Request $r, array $p): Response
    {
        $n = Notices::bySlug($p['slug']);
        if (!$n || $n['kind'] !== $p['kind']) {
            throw new HttpException(404, 'Notice not found');
        }
        Database::query('UPDATE notices SET views=views+1 WHERE id=?', [$n['id']]);
        $related = Notices::recent(['kind' => $n['kind'], 'county' => $n['county']], 6);
        $related = array_values(array_filter($related, static fn($x) => $x['id'] !== $n['id']));
        return View::page('notice', self::base([
            'title' => $n['title'] . ' · ' . $n['kind_label'] . ' · Co. ' . $n['county'] . ' — ME News Ireland',
            'description' => excerpt($n['body'] ?: ($n['kind_label'] . ' for ' . $n['title'] . ', ' . ($n['town'] ?: 'Co. ' . $n['county'])), 180),
            'notice' => $n, 'related' => array_slice($related, 0, 5),
            'jsonld' => Notices::eventJsonLd($n),
            'bodyClass' => 'page-notices',
        ]));
    }

    public static function submitForm(Request $r): Response
    {
        $kind = $r->query('kind', 'death', 20);
        return View::page('notice-submit', self::base([
            'title' => 'Place a notice — ME News Ireland',
            'description' => 'Place a death notice, in memoriam, event, job, planning notice, lost pet or club result. Free for families and clubs.',
            'kind' => Notices::kind($kind) ? $kind : 'death',
            'kinds' => Notices::KINDS, 'counties' => Locations::countyNames(), 'county' => Visitor::county(),
            'bodyClass' => 'page-notices',
        ]));
    }

    public static function submit(Request $r): Response
    {
        $row = Notices::submit($_POST, Auth::user(), $r->ip());
        return Response::json([
            'ok' => true, 'id' => $row['id'], 'status' => $row['status'],
            'message' => $row['verified_at'] ? 'Thanks. Your notice is with an editor and usually goes live within a few hours.' : 'Thanks. Check your email for a one-tap confirmation link; an editor then publishes the notice, usually within a few hours.',
        ]);
    }

    public static function confirmNotice(Request $r, array $p): Response
    {
        $n = Notices::confirm($p['token']);
        return View::page('confirmed', self::base([
            'title' => ($n ? 'Notice confirmed' : 'Link not found') . ' — ME News Ireland',
            'heading' => $n ? 'Thanks, that’s confirmed.' : 'That link doesn’t match anything.',
            'text' => $n ? 'An editor will check “' . $n['title'] . '” and publish it, usually within a few hours. You’ll get an email when it’s live.' : 'The link may have been used already or copied incompletely. You can place the notice again.',
            'link' => '/notices', 'linkText' => 'Back to notices',
        ]));
    }

    // ------------------------------------------------------------------- alerts

    public static function alerts(Request $r): Response
    {
        $county = self::county($r);
        $status = Alerts::status($county);
        $warnings = Alerts::forCounty($county);
        $closures = Alerts::closures($county, 3);
        return View::page('alerts', self::base([
            'title' => ($status ? $status . ' warning' : 'Weather warnings & school closures') . ($county ? ' · Co. ' . $county : ' · Ireland') . ' — ME News Ireland',
            'description' => 'Met Éireann weather warnings by county and school closures submitted by principals' . ($county ? ' for County ' . $county : '') . '. Sign up for alerts by email.',
            'county' => $county, 'status' => $status, 'warnings' => $warnings, 'closures' => $closures,
            'allWarnings' => Alerts::warnings(), 'counties' => Locations::countyNames(), 'kinds' => Alerts::KINDS,
            'whatsapp' => Database::setting('whatsapp_channel_' . slugify((string)$county)) ?: Database::setting('whatsapp_channel'),
            'bodyClass' => 'page-alerts' . ($status ? ' is-live is-' . strtolower($status) : ' is-dormant'),
            'robots' => null,
        ]));
    }

    public static function subscribe(Request $r): Response
    {
        $out = Alerts::subscribe($_POST + ['kinds' => (array)($_POST['kinds'] ?? [])], Auth::user(), $r->ip());
        $out['message'] = $out['confirmed'] ? 'Done — you’re signed up for Co. ' . $out['county'] . '.' : 'Check your inbox for a one-tap confirmation link.';
        return Response::json($out);
    }

    public static function confirmSubscription(Request $r, array $p): Response
    {
        $s = Alerts::confirmSubscription($p['token']);
        return View::page('confirmed', self::base([
            'title' => 'Alerts confirmed — ME News Ireland',
            'heading' => $s ? 'You’re set up for Co. ' . $s['county'] . '.' : 'That link doesn’t match anything.',
            'text' => $s ? 'You’ll get: ' . implode(', ', array_map(static fn($k) => strtolower(Alerts::KINDS[$k] ?? $k), explode(',', $s['kinds']))) . '. Every email has a one-tap unsubscribe.' : 'The link may have been used already.',
            'link' => '/alerts' . ($s ? '?county=' . rawurlencode($s['county']) : ''), 'linkText' => 'Alerts for your county',
        ]));
    }

    public static function unsubscribe(Request $r, array $p): Response
    {
        $s = Alerts::unsubscribe($p['token']);
        return View::page('confirmed', self::base([
            'title' => 'Unsubscribed — ME News Ireland',
            'heading' => $s ? 'Unsubscribed.' : 'That link doesn’t match anything.',
            'text' => $s ? 'No more emails for Co. ' . $s['county'] . '. Changed your mind? Sign up again any time.' : '',
            'link' => '/', 'linkText' => 'Back to ME News',
        ]));
    }

    public static function submitClosure(Request $r): Response
    {
        $out = Alerts::submitClosure($_POST, $r->ip());
        $out['message'] = 'Thanks. Confirm from the school email we just sent and the closure goes live immediately.';
        return Response::json($out);
    }

    public static function confirmClosure(Request $r, array $p): Response
    {
        $c = Alerts::confirmClosure($p['token']);
        return View::page('confirmed', self::base([
            'title' => 'Closure confirmed — ME News Ireland',
            'heading' => $c ? $c['school'] . ' is now listed as closed.' : 'That link doesn’t match anything.',
            'text' => $c ? 'Parents in Co. ' . $c['county'] . ' who asked for closure alerts have been emailed. Email us if the school reopens earlier than planned.' : '',
            'link' => '/alerts' . ($c ? '?county=' . rawurlencode($c['county']) : ''), 'linkText' => 'See the closures list',
        ]));
    }

    /** JSON for the header/bottom-bar badge and the county pages. */
    public static function alertsApi(Request $r): Response
    {
        $county = self::county($r);
        return Response::json(['status' => Alerts::status($county), 'headline' => Alerts::headline($county), 'warnings' => Alerts::forCounty($county), 'closures' => Alerts::closures($county, 2)]);
    }

    // -------------------------------------------------------------------- polls

    public static function poll(Request $r): Response
    {
        $county = self::county($r);
        $poll = Polls::current($county);
        $past = Database::all("SELECT id,week,question,closes_at FROM polls WHERE status<>'open' OR closes_at<? ORDER BY week DESC LIMIT 8", [now()]);
        return View::page('poll', self::base([
            'title' => ($poll ? $poll['question'] : 'Weekly county poll') . ' — ME News Ireland',
            'description' => 'This week’s county poll and the county-by-county breakdown.',
            'poll' => $poll, 'county' => $county, 'past' => $past, 'counties' => Locations::countyNames(),
        ]));
    }

    public static function vote(Request $r): Response
    {
        $county = Visitor::county() ?? (Visitor::position() ? (\MeNews\Support\Geo::nearest(Visitor::position()['lat'], Visitor::position()['lng'])['county'] ?? null) : null);
        $poll = Polls::vote($r->post('poll_id', '', 40), (int)$r->post('option', '-1'), $county, $r->ip());
        return Response::json($poll);
    }

    // -------------------------------------------------------------- cron / digest

    /** GET /cron/daily?key=CRON_KEY — send the 7am county emails (idempotent per day), push warnings, expire notices. */
    public static function cronDaily(Request $r): Response
    {
        $key = \MeNews\Config::get('CRON_KEY');
        if ($key === '' || !hash_equals($key, $r->query('key', '', 200))) {
            throw new HttpException(403, 'Invalid cron key');
        }
        set_time_limit(280);
        return Response::json([
            'daily_sent' => Digest::sendDue((bool)$r->query('force')),
            'monthly_sent' => Digest::sendMonthly((bool)$r->query('force_monthly')),
            'warnings_sent' => Alerts::pushWarnings(),
            'notices_expired' => Notices::expire(),
            'time' => now(),
        ]);
    }

    /** Preview today's digest for a county (staff only unless in development). */
    public static function digestPreview(Request $r, array $p): Response
    {
        if (\MeNews\Config::production()) {
            Auth::require(['editor', 'admin']);
        }
        $county = null;
        foreach (Locations::countyNames() as $c) {
            if (slugify($c) === $p['slug']) {
                $county = $c;
            }
        }
        if (!$county) {
            throw new HttpException(404, 'County not found');
        }
        return Response::html(Digest::html($county, 'preview'));
    }

    /** Text for the 90-second county audio bulletin (read aloud in the browser). */
    public static function bulletin(Request $r): Response
    {
        $county = self::county($r);
        return Response::json(Digest::bulletin($county));
    }
}
