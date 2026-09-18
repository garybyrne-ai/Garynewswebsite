<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Stories;
use MeNews\Support\Daily;

/**
 * "Your Wicklow morning": the 7am county email (five stories, weather, deaths, what's on)
 * and the 90-second spoken bulletin built from the same material.
 */
final class Digest
{
    /** Everything the county email and bulletin need, in one array. */
    public static function build(?string $county): array
    {
        $stories = $county ? Stories::feed(['county' => $county], 5) : [];
        if (count($stories) < 5) {
            $seen = array_column($stories, 'id');
            foreach (Stories::feed(['category' => 'National', 'exclude' => $seen ?: ['-']], 5 - count($stories)) as $s) {
                $stories[] = $s;
            }
        }
        $weather = Weather::today();
        $city = null;
        if ($weather && $county) {
            // Nearest forecast city to the county centroid (Wicklow → Dublin, Kerry → Cork, Donegal → Letterkenny…)
            $centre = \MeNews\Support\Geo::county($county);
            $best = PHP_FLOAT_MAX;
            foreach ($weather['cities'] as $i => $c) {
                $ll = Weather::CITIES[$i] ?? null;
                $d = $centre && $ll ? \MeNews\Support\Geo::distanceKm($centre[0], $centre[1], $ll[1], $ll[2]) : PHP_FLOAT_MAX;
                if ($c['name'] === $county || $d < $best) {
                    $best = $c['name'] === $county ? -1 : $d;
                    $city = $c;
                }
            }
        }
        return [
            'county' => $county, 'date' => Daily::longDate(), 'edition' => Daily::edition(),
            'stories' => $stories,
            'deaths' => $county ? Notices::recent(['county' => $county, 'kind' => 'death'], 6) : [],
            'events' => $county ? Notices::recent(['county' => $county, 'kind' => 'event', 'upcoming' => true], 4) : [],
            'warning' => Alerts::headline($county), 'closures' => Alerts::closures($county, 1),
            'weather' => $weather, 'city' => $city, 'focal' => Daily::focal(),
            'signal' => Signal::featured(3, 'today'),
        ];
    }

    public static function subject(array $d): string
    {
        $lead = $d['stories'][0]['title'] ?? 'Your morning briefing';
        return 'Your ' . ($d['county'] ?: 'Irish') . ' morning · ' . excerpt($lead, 70);
    }

    public static function html(string $county, string $token): string
    {
        $d = self::build($county);
        $base = rtrim(Config::get('PUBLIC_BASE_URL', ''), '/');
        $h = '<p style="color:#59685f;font-size:12px;letter-spacing:.15em;text-transform:uppercase;margin:0 0 6px">Edition ' . (int)$d['edition'] . ' · ' . e($d['date']) . '</p>';
        $h .= '<h1 style="font-size:24px;margin:0 0 14px;letter-spacing:-.02em">Your ' . e($county) . ' morning</h1>';
        if ($d['warning']) {
            $h .= '<p style="background:#fff3d6;border-left:4px solid #c7780a;padding:10px 12px;border-radius:6px"><b>Weather:</b> ' . e($d['warning']) . ' · <a href="' . e($base . '/alerts?county=' . rawurlencode($county)) . '" style="color:#139a5c">details</a></p>';
        }
        if ($d['closures']) {
            $h .= '<p style="background:#fde8ec;border-left:4px solid #d92645;padding:10px 12px;border-radius:6px"><b>School closures today:</b> ' . e(implode('; ', array_map(static fn($c) => $c['school'] . ($c['town'] ? ' (' . $c['town'] . ')' : ''), $d['closures']))) . '</p>';
        }
        if ($d['city']) {
            $h .= '<p style="color:#33423a"><b>' . e($d['city']['name']) . ' today:</b> ' . e($d['city']['label']) . ', ' . (int)$d['city']['min'] . '–' . (int)$d['city']['max'] . '°, wind ' . (int)$d['city']['wind'] . ' km/h. Sunrise ' . e($d['weather']['sunrise']) . ', sunset ' . e($d['weather']['sunset']) . '.</p>';
        }
        $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">Five to know</h2><ol style="padding-left:20px;margin:0">';
        foreach ($d['stories'] as $s) {
            $h .= '<li style="margin:0 0 10px"><a href="' . e($base . $s['url']) . '" style="color:#0b1410;font-weight:700;text-decoration:none">' . e($s['title']) . '</a><br><span style="color:#59685f;font-size:13px">' . e($s['kind'] === 'wire' ? $s['source_name'] : 'Community report') . ($s['location_name'] ? ' · ' . e($s['location_name']) : '') . '</span></li>';
        }
        $h .= '</ol>';
        if ($d['deaths']) {
            $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">Deaths</h2><ul style="padding-left:20px;margin:0">';
            foreach ($d['deaths'] as $n) {
                $h .= '<li style="margin:0 0 6px"><a href="' . e($base . $n['url']) . '" style="color:#0b1410;font-weight:700;text-decoration:none">' . e($n['title']) . '</a> <span style="color:#59685f;font-size:13px">' . e($n['town'] ?: '') . ($n['funeral_at'] ? ' · funeral ' . e(date_irish($n['funeral_at'], 'D H:i')) : '') . '</span></li>';
            }
            $h .= '</ul>';
        }
        if ($d['events']) {
            $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">What’s on</h2><ul style="padding-left:20px;margin:0">';
            foreach ($d['events'] as $n) {
                $h .= '<li style="margin:0 0 6px"><a href="' . e($base . $n['url']) . '" style="color:#0b1410;font-weight:700;text-decoration:none">' . e($n['title']) . '</a> <span style="color:#59685f;font-size:13px">' . e(date_irish($n['event_at'], 'D j M H:i')) . ($n['venue'] ? ' · ' . e($n['venue']) : '') . '</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '<p style="margin-top:22px;color:#33423a"><b>Focal an lae:</b> <i>' . e($d['focal']['irish']) . '</i> — ' . e($d['focal']['english']) . '</p>';
        $h .= '<p style="margin-top:18px"><a href="' . e($base . '/county/' . slugify($county)) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:10px 16px;border-radius:999px;text-decoration:none;font-weight:700">All of Co. ' . e($county) . ' →</a> &nbsp; <a href="' . e($base . '/#community') . '" style="color:#d92645;font-weight:700;text-decoration:none">Report something</a></p>';
        if ($token !== 'preview') {
            $h .= '<p style="color:#59685f;font-size:12px;margin-top:22px">You asked for the ' . e($county) . ' morning email. <a href="' . e($base . '/alerts/unsubscribe/' . $token) . '" style="color:#59685f">Unsubscribe</a></p>';
        }
        return $h;
    }

    /**
     * Send the digest to every confirmed subscriber whose county-morning hasn't gone out today
     * (Irish time). Safe to call every few minutes from cron; it only sends after 07:00.
     */
    public static function sendDue(bool $force = false): int
    {
        $today = Daily::today();
        if (!$force && (int)$today->format('G') < 7) {
            return 0;
        }
        $day = $today->format('Y-m-d');
        $rows = Database::all("SELECT id,email,county,token,last_daily_at FROM alert_subscriptions WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND (','||kinds||',') LIKE '%,daily,%' ORDER BY county");
        $cache = [];
        $n = 0;
        foreach ($rows as $s) {
            if (!$force && $s['last_daily_at'] && str_starts_with(date_irish($s['last_daily_at'], 'Y-m-d'), $day)) {
                continue;
            }
            $cache[$s['county']] ??= self::html($s['county'], '{{token}}');
            $html = str_replace('{{token}}', $s['token'], $cache[$s['county']]);
            if (Mailer::send($s['email'], self::subject(self::build($s['county'])), $html)) {
                Database::query('UPDATE alert_subscriptions SET last_daily_at=? WHERE id=?', [now(), $s['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Members-only monthly county newsletter: the month's most-read and most-confirmed community
     * reports, notices and the poll for each ME+ member's home county. Sent on the 1st (Irish time).
     */
    public static function monthlyHtml(string $county, string $name): string
    {
        $base = rtrim(Config::get('PUBLIC_BASE_URL', ''), '/');
        $since = gmdate('Y-m-d\TH:i:s', strtotime('first day of last month 00:00')) . '+00:00';
        $monthLabel = date('F Y', strtotime('first day of last month'));
        $rows = Database::all("SELECT id,slug,title,views,location_name,kind,source_name,(SELECT COUNT(*) FROM confirmations c WHERE c.story_id=s.id) AS confirmations FROM stories s WHERE status='published' AND county LIKE ? AND COALESCE(published_at,created_at)>=? ORDER BY views DESC LIMIT 8", [$county ?: '%', $since]);
        $reporters = Database::all("SELECT u.display_name,u.handle,COUNT(*) AS n FROM stories s JOIN users u ON u.id=s.author_user_id WHERE s.kind='community' AND s.status='published' AND s.county LIKE ? AND COALESCE(s.published_at,s.created_at)>=? GROUP BY u.id ORDER BY n DESC LIMIT 5", [$county ?: '%', $since]);
        $deaths = (int)Database::value("SELECT COUNT(*) FROM notices WHERE kind='death' AND status='published' AND county=? AND created_at>=?", [$county, $since]);
        $events = Notices::recent(['county' => $county, 'kind' => 'event', 'upcoming' => true], 5);
        $h = '<p style="color:#59685f;font-size:12px;letter-spacing:.15em;text-transform:uppercase;margin:0 0 6px">ME+ members · ' . e($monthLabel) . '</p>';
        $h .= '<h1 style="font-size:24px;margin:0 0 6px;letter-spacing:-.02em">' . e($county ? 'Co. ' . $county : 'Ireland') . ', the month that was</h1>';
        $h .= '<p style="color:#33423a;margin:0 0 16px">Thanks for funding local reporting, ' . e(explode(' ', $name)[0]) . '. Here is what your county read, confirmed and sent in during ' . e($monthLabel) . '.</p>';
        $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">Most read</h2><ol style="padding-left:20px;margin:0">';
        foreach ($rows as $s) {
            $h .= '<li style="margin:0 0 10px"><a href="' . e($base . '/story/' . $s['slug']) . '" style="color:#0b1410;font-weight:700;text-decoration:none">' . e($s['title']) . '</a><br><span style="color:#59685f;font-size:13px">' . e($s['kind'] === 'wire' ? $s['source_name'] : 'Community report') . ($s['location_name'] ? ' · ' . e($s['location_name']) : '') . ' · ' . (int)$s['views'] . ' reads' . ((int)$s['confirmations'] ? ' · confirmed by ' . (int)$s['confirmations'] . ' neighbours' : '') . '</span></li>';
        }
        $h .= $rows ? '</ol>' : '<li>A quiet month on the community desk — <a href="' . e($base . '/#community') . '" style="color:#d92645">send in what you see</a>.</li></ol>';
        if ($reporters) {
            $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">Who reported</h2><p style="margin:0;color:#33423a">' . e(implode(', ', array_map(static fn($r) => $r['display_name'] . ' (' . (int)$r['n'] . ')', $reporters))) . '</p>';
        }
        $h .= '<p style="margin-top:16px;color:#33423a"><b>' . (int)$deaths . '</b> death notice' . ($deaths === 1 ? '' : 's') . ' were published for the county last month. ';
        $h .= 'As a member you can follow up to ten areas for death notices, closures and warnings from your <a href="' . e($base . '/dashboard#follows') . '" style="color:#139a5c">dashboard</a>.</p>';
        if ($events) {
            $h .= '<h2 style="font-size:16px;letter-spacing:.1em;text-transform:uppercase;color:#139a5c;margin:22px 0 8px">Coming up</h2><ul style="padding-left:20px;margin:0">';
            foreach ($events as $n) {
                $h .= '<li style="margin:0 0 6px"><a href="' . e($base . $n['url']) . '" style="color:#0b1410;font-weight:700;text-decoration:none">' . e($n['title']) . '</a> <span style="color:#59685f;font-size:13px">' . e(date_irish($n['event_at'], 'D j M H:i')) . ($n['venue'] ? ' · ' . e($n['venue']) : '') . '</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '<p style="margin-top:22px"><a href="' . e($base . ($county ? '/county/' . slugify($county) : '/')) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:10px 16px;border-radius:999px;text-decoration:none;font-weight:700">Read the full archive →</a></p>';
        $h .= '<p style="color:#59685f;font-size:12px;margin-top:22px">Sent once a month to ME+ members. Manage your membership in your <a href="' . e($base . '/dashboard#billing') . '" style="color:#59685f">dashboard</a>.</p>';
        return $h;
    }

    /** Send the monthly members' newsletter on the 1st of the month; safe to call daily. */
    public static function sendMonthly(bool $force = false): int
    {
        $today = Daily::today();
        if (!$force && ((int)$today->format('j') !== 1 || (int)$today->format('G') < 8)) {
            return 0;
        }
        $month = $today->format('Y-m');
        $n = 0;
        $cache = [];
        foreach (Database::all("SELECT id,email,display_name,home_county,last_monthly_at FROM users WHERE plan='ME+' ORDER BY home_county") as $u) {
            if (!$force && $u['last_monthly_at'] && str_starts_with(date_irish($u['last_monthly_at'], 'Y-m'), $month)) {
                continue;
            }
            $county = (string)($u['home_county'] ?? '');
            $cache[$county] ??= self::monthlyHtml($county, '{{name}}');
            $html = str_replace(e('{{name}}'), e($u['display_name']), $cache[$county]);
            if (Mailer::send($u['email'], ($county ? 'Co. ' . $county : 'Ireland') . ' — your ME+ monthly', $html)) {
                Database::query('UPDATE users SET last_monthly_at=? WHERE id=?', [now(), $u['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Spoken bulletin script (~90 seconds at a natural pace). */
    public static function bulletin(?string $county): array
    {
        $d = self::build($county);
        $name = $county ? 'County ' . $county : 'Ireland';
        $lines = ['Good ' . (['morning', 'afternoon', 'evening'][min(2, intdiv((int)Daily::today()->format('G'), 8))]) . '. This is the ME News bulletin for ' . $name . ', ' . $d['date'] . '.'];
        if ($d['warning']) {
            $lines[] = 'First, the weather: Met Éireann has a ' . $d['warning'] . '.';
        } elseif ($d['city']) {
            $lines[] = 'The weather in ' . $d['city']['name'] . ': ' . strtolower($d['city']['label']) . ', with a high of ' . (int)$d['city']['max'] . ' degrees.';
        }
        if ($d['closures']) {
            $lines[] = 'School closures today: ' . implode(', ', array_map(static fn($c) => $c['school'], $d['closures'])) . '.';
        }
        $lines[] = 'The top stories.';
        foreach (array_slice($d['stories'], 0, 5) as $i => $s) {
            $lines[] = ($s['kind'] === 'wire' ? $s['source_name'] . ' reports: ' : 'From the ground: ') . rtrim($s['title'], '.') . '.' . ($s['summary'] && $i < 2 ? ' ' . excerpt($s['summary'], 160) : '');
        }
        if ($d['deaths']) {
            $lines[] = 'Deaths notified in ' . $name . ': ' . implode('; ', array_map(static fn($n) => $n['title'] . ($n['town'] ? ', ' . $n['town'] : ''), array_slice($d['deaths'], 0, 4))) . '.';
        }
        if ($d['events']) {
            $lines[] = 'Coming up: ' . implode('; ', array_map(static fn($n) => $n['title'] . ' on ' . date_irish($n['event_at'], 'l'), array_slice($d['events'], 0, 3))) . '.';
        }
        $lines[] = 'And the Irish word of the day is ' . $d['focal']['irish'] . ', meaning ' . $d['focal']['english'] . '. That’s the bulletin. Report what you can see at ME News.';
        return ['county' => $county, 'lines' => $lines, 'text' => implode(' ', $lines), 'lang' => 'en-IE'];
    }
}
