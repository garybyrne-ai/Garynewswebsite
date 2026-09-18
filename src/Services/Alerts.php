<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Support\Locations;

/**
 * The page that stays dormant and lights up: Met Éireann warnings by county (open data,
 * cached 10 minutes), school closures submitted by principals, and the email subscriptions
 * that power death-notice alerts and the 7am county digest.
 */
final class Alerts
{
    /** Met Éireann region codes (FIPS-style EIxx) → county. */
    private const REGIONS = [
        'EI01' => 'Carlow', 'EI02' => 'Cavan', 'EI03' => 'Clare', 'EI04' => 'Cork', 'EI06' => 'Donegal', 'EI07' => 'Dublin',
        'EI10' => 'Galway', 'EI11' => 'Kerry', 'EI12' => 'Kildare', 'EI13' => 'Kilkenny', 'EI14' => 'Laois', 'EI15' => 'Leitrim',
        'EI16' => 'Limerick', 'EI18' => 'Longford', 'EI19' => 'Louth', 'EI20' => 'Mayo', 'EI21' => 'Meath', 'EI22' => 'Monaghan',
        'EI23' => 'Offaly', 'EI24' => 'Roscommon', 'EI25' => 'Sligo', 'EI26' => 'Tipperary', 'EI27' => 'Waterford',
        'EI29' => 'Westmeath', 'EI30' => 'Wexford', 'EI31' => 'Wicklow',
    ];

    public const KINDS = ['daily' => 'Your 7am county morning email', 'deaths' => 'Death notices as they are published', 'warnings' => 'Weather warnings for your county', 'closures' => 'School closures', 'planning' => 'Planning and statutory notices'];

    private static ?array $cache = null;

    /** All current Met Éireann warnings, normalised. Cached in storage/cache for 10 minutes. */
    public static function warnings(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = Config::storage() . '/cache/met-warnings.json';
        if (is_file($file) && filemtime($file) > time() - 600) {
            return self::$cache = json_decode((string)file_get_contents($file), true) ?: [];
        }
        $out = [];
        try {
            $raw = json_decode(Remote::get('https://www.met.ie/Open_Data/json/warning_IRELAND.json', ['Accept: application/json'], 12), true);
            foreach (is_array($raw) ? $raw : [] as $w) {
                if (($w['status'] ?? 'Warning') === 'Cancel') {
                    continue;
                }
                $counties = [];
                foreach ($w['regions'] ?? [] as $code) {
                    $c = self::REGIONS[$code] ?? null;
                    if ($c) {
                        $counties[] = $c;
                    }
                }
                $level = ucfirst(strtolower((string)($w['level'] ?? 'Yellow')));
                $type = trim(explode(';', (string)($w['type'] ?? ''))[0]);
                $out[] = [
                    'id' => (string)($w['capId'] ?? md5(json_encode($w))),
                    'level' => in_array($level, ['Yellow', 'Orange', 'Red'], true) ? $level : 'Yellow',
                    'kind' => ucfirst(strtolower($type)),
                    'headline' => (string)($w['headline'] ?? 'Weather warning'),
                    'description' => (string)($w['description'] ?? ''),
                    'onset' => (string)($w['onset'] ?? ''), 'expiry' => (string)($w['expiry'] ?? ''), 'issued' => (string)($w['issued'] ?? ''),
                    'counties' => $counties, 'national' => count($counties) >= 24,
                    'advisory' => str_contains(strtolower((string)($w['headline'] ?? '')), 'advisory'),
                ];
            }
            usort($out, static fn($a, $b) => self::rank($b['level']) <=> self::rank($a['level']));
            file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            error_log('Met Éireann warnings: ' . $e->getMessage());
            if (is_file($file)) {
                $out = json_decode((string)file_get_contents($file), true) ?: [];
            }
        }
        return self::$cache = $out;
    }

    public static function rank(string $level): int
    {
        return ['Red' => 3, 'Orange' => 2, 'Yellow' => 1][$level] ?? 0;
    }

    /** Warnings that cover a county (or every warning when no county). Advisories are listed last. */
    public static function forCounty(?string $county): array
    {
        $all = self::warnings();
        if ($county) {
            $all = array_values(array_filter($all, static fn($w) => in_array($county, $w['counties'], true)));
        }
        usort($all, static fn($a, $b) => [$a['advisory'], -self::rank($a['level'])] <=> [$b['advisory'], -self::rank($b['level'])]);
        return $all;
    }

    /** One-line status for the masthead: the most serious live (non-advisory) warning. */
    public static function headline(?string $county): string
    {
        foreach (self::forCounty($county) as $w) {
            if ($w['advisory'] || strtotime($w['expiry'] ?: 'now') < time()) {
                continue;
            }
            $until = $w['expiry'] ? ' until ' . date_irish($w['expiry'], 'D H:i') : '';
            return $w['level'] . ' ' . strtolower($w['kind']) . ' warning' . ($w['national'] ? '' : ($county ? ' · ' . $county : ' · ' . count($w['counties']) . ' counties')) . $until;
        }
        return '';
    }

    /** The highest live level nationally: '', 'Yellow', 'Orange', 'Red'. Drives the page's dormant/live state. */
    public static function status(?string $county = null): string
    {
        $top = '';
        foreach (self::forCounty($county) as $w) {
            if (!$w['advisory'] && self::rank($w['level']) > self::rank($top)) {
                $top = $w['level'];
            }
        }
        return $top;
    }

    // ------------------------------------------------------------ school closures

    public static function closures(?string $county = null, int $days = 2): array
    {
        $from = date('Y-m-d', time() - 86400);
        $to = date('Y-m-d', time() + $days * 86400);
        return Database::all("SELECT id,school,county,town,closed_on,reopens_on,reason,contact_role,verified_at,published_at FROM closures WHERE status='published' AND closed_on BETWEEN ? AND ?" . ($county ? ' AND county=?' : '') . ' ORDER BY closed_on DESC, county, school', $county ? [$from, $to, $county] : [$from, $to]);
    }

    public static function submitClosure(array $in, string $ip): array
    {
        RateLimiter::hit($ip . '|closure', 10, 3600, 'Too many submissions. Try again later.');
        $school = trim((string)($in['school'] ?? ''));
        $county = trim((string)($in['county'] ?? ''));
        $day = trim((string)($in['closed_on'] ?? ''));
        $email = mb_strtolower(trim((string)($in['contact_email'] ?? '')));
        if (mb_strlen($school) < 3) {
            throw new HttpException(400, 'Enter the school name');
        }
        if (!in_array($county, Locations::countyNames(), true)) {
            throw new HttpException(400, 'Choose a county');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new HttpException(400, 'Choose the date the school is closed');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter a school email address so we can confirm');
        }
        $id = uuid();
        $token = bin2hex(random_bytes(20));
        Database::insert('closures', [
            'id' => $id, 'created_at' => now(), 'status' => 'review', 'school' => mb_substr($school, 0, 140), 'county' => $county,
            'town' => mb_substr(trim((string)($in['town'] ?? '')), 0, 80), 'closed_on' => $day,
            'reopens_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['reopens_on'] ?? '')) ? $in['reopens_on'] : null,
            'reason' => mb_substr(trim((string)($in['reason'] ?? '')), 0, 300), 'contact_name' => mb_substr(trim((string)($in['contact_name'] ?? '')), 0, 100),
            'contact_role' => mb_substr(trim((string)($in['contact_role'] ?? 'Principal')), 0, 60), 'contact_email' => $email, 'verify_token' => $token,
        ]);
        Mailer::send($email, 'Confirm the closure of ' . $school, '<p>Tap to confirm that <b>' . e($school) . '</b> is closed on <b>' . e(date('l j F', strtotime($day) ?: time())) . '</b>' . ($in['reason'] ?? '' ? ' (' . e($in['reason']) . ')' : '') . '. It appears on the ME News closures list as soon as you confirm, and an editor can remove it if anything looks wrong.</p>'
            . '<p><a href="' . e(absolute_url('/alerts/closures/confirm/' . $token)) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700">Confirm closure</a></p>');
        return ['id' => $id, 'status' => 'review'];
    }

    /** A confirmed school email publishes the closure immediately (principals are the source of truth). */
    public static function confirmClosure(string $token): ?array
    {
        $c = Database::one('SELECT * FROM closures WHERE verify_token=?', [$token]);
        if (!$c) {
            return null;
        }
        if (!$c['verified_at']) {
            Database::query("UPDATE closures SET verified_at=?,status='published',published_at=? WHERE id=?", [now(), now(), $c['id']]);
            self::notifyClosure($c);
        }
        return $c;
    }

    private static function notifyClosure(array $c): void
    {
        $rows = Database::all("SELECT email, token FROM alert_subscriptions WHERE county=? AND confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND (','||kinds||',') LIKE '%,closures,%'", [$c['county']]);
        foreach ($rows as $s) {
            Mailer::send($s['email'], 'School closure: ' . $c['school'] . ' (' . date('D j M', strtotime($c['closed_on']) ?: time()) . ')',
                '<p><b>' . e($c['school']) . '</b>' . ($c['town'] ? ', ' . e($c['town']) : '') . ', Co. ' . e($c['county']) . ' is closed on <b>' . e(date('l j F', strtotime($c['closed_on']) ?: time())) . '</b>.' . ($c['reason'] ? ' ' . e($c['reason']) . '.' : '') . '</p>'
                . '<p><a href="' . e(absolute_url('/alerts')) . '" style="color:#139a5c;font-weight:700">All closures and warnings →</a></p>'
                . '<p style="color:#59685f;font-size:12px"><a href="' . e(absolute_url('/alerts/unsubscribe/' . $s['token'])) . '" style="color:#59685f">Unsubscribe</a></p>');
        }
    }

    // ---------------------------------------------------------- subscriptions

    /** Active subscriptions for a member's email, for the dashboard. */
    public static function forEmail(string $email): array
    {
        $rows = Database::all('SELECT id,county,town,kinds,confirmed_at,created_at,last_daily_at FROM alert_subscriptions WHERE email=? AND unsubscribed_at IS NULL ORDER BY county', [mb_strtolower($email)]);
        foreach ($rows as &$r) {
            $r['kinds'] = array_values(array_filter(explode(',', (string)$r['kinds'])));
            $r['kind_labels'] = array_map(static fn($k) => self::KINDS[$k] ?? $k, $r['kinds']);
            $r['confirmed'] = (bool)$r['confirmed_at'];
        }
        return $rows;
    }

    public static function remove(int $id, string $email): bool
    {
        return Database::query('UPDATE alert_subscriptions SET unsubscribed_at=? WHERE id=? AND email=?', [now(), $id, mb_strtolower($email)])->rowCount() > 0;
    }

    public static function subscribe(array $in, ?array $user, string $ip): array
    {
        RateLimiter::hit($ip . '|subscribe', 15, 3600, 'Too many sign-ups from this connection.');
        $email = mb_strtolower(trim((string)($in['email'] ?? ($user['email'] ?? ''))));
        $county = trim((string)($in['county'] ?? ''));
        $kinds = array_values(array_intersect(array_keys(self::KINDS), (array)($in['kinds'] ?? ['daily'])));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter a valid email');
        }
        if (!in_array($county, Locations::countyNames(), true)) {
            throw new HttpException(400, 'Choose a county');
        }
        if (!$kinds) {
            $kinds = ['daily'];
        }
        // ME+ covers up to ten areas; a free account gets one county.
        $limit = Membership::alertLimit($user, $email);
        $others = (int)Database::value('SELECT COUNT(*) FROM alert_subscriptions WHERE email=? AND county<>? AND unsubscribed_at IS NULL', [$email, $county]);
        if ($others >= $limit) {
            throw new HttpException(403, $limit === 1
                ? 'Free accounts get alerts for one county. ME+ members can follow up to ten areas — ' . Membership::priceLabel() . ' or ' . Membership::annualLabel() . '.'
                : 'ME+ covers up to ten areas. Remove one in your dashboard to add another.');
        }
        $existing = Database::one('SELECT * FROM alert_subscriptions WHERE email=? AND county=?', [$email, $county]);
        $token = $existing['token'] ?? bin2hex(random_bytes(20));
        $town = mb_substr(trim((string)($in['town'] ?? '')), 0, 80);
        if ($existing) {
            Database::query('UPDATE alert_subscriptions SET kinds=?,town=?,unsubscribed_at=NULL,user_id=COALESCE(user_id,?) WHERE id=?', [implode(',', $kinds), $town, $user['id'] ?? null, $existing['id']]);
        } else {
            Database::insert('alert_subscriptions', ['email' => $email, 'county' => $county, 'town' => $town, 'kinds' => implode(',', $kinds), 'token' => $token, 'user_id' => $user['id'] ?? null, 'created_at' => now(), 'confirmed_at' => $user && strcasecmp($user['email'], $email) === 0 ? now() : null]);
        }
        $confirmed = (bool)Database::value('SELECT confirmed_at FROM alert_subscriptions WHERE token=?', [$token]);
        if (!$confirmed) {
            Mailer::send($email, 'Confirm your ' . $county . ' alerts from ME News',
                '<p>One tap and you are set up for <b>' . e(implode(', ', array_map(static fn($k) => strtolower(self::KINDS[$k]), $kinds))) . '</b> for Co. ' . e($county) . ($town ? ' (' . e($town) . ')' : '') . '.</p>'
                . '<p><a href="' . e(absolute_url('/alerts/confirm/' . $token)) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700">Confirm my alerts</a></p>'
                . '<p style="color:#59685f;font-size:13px">Didn’t ask for this? Ignore it and nothing will be sent.</p>');
        }
        return ['ok' => true, 'confirmed' => $confirmed, 'kinds' => $kinds, 'county' => $county];
    }

    public static function confirmSubscription(string $token): ?array
    {
        $s = Database::one('SELECT * FROM alert_subscriptions WHERE token=?', [$token]);
        if ($s) {
            Database::query('UPDATE alert_subscriptions SET confirmed_at=COALESCE(confirmed_at,?),unsubscribed_at=NULL WHERE id=?', [now(), $s['id']]);
        }
        return $s;
    }

    public static function unsubscribe(string $token): ?array
    {
        $s = Database::one('SELECT * FROM alert_subscriptions WHERE token=?', [$token]);
        if ($s) {
            Database::query('UPDATE alert_subscriptions SET unsubscribed_at=? WHERE id=?', [now(), $s['id']]);
        }
        return $s;
    }

    /** Send warning emails once per warning per subscriber (state kept in storage/cache). */
    public static function pushWarnings(): int
    {
        $file = Config::storage() . '/cache/warnings-sent.json';
        $sent = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        $n = 0;
        foreach (self::warnings() as $w) {
            if ($w['advisory'] || self::rank($w['level']) < 1 || isset($sent[$w['id']])) {
                continue;
            }
            $sent[$w['id']] = time();
            $subs = Database::all("SELECT email, county, token FROM alert_subscriptions WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND (','||kinds||',') LIKE '%,warnings,%'");
            foreach ($subs as $s) {
                if (!$w['national'] && !in_array($s['county'], $w['counties'], true)) {
                    continue;
                }
                Mailer::send($s['email'], 'Met Éireann ' . $w['level'] . ' ' . strtolower($w['kind']) . ' warning · Co. ' . $s['county'],
                    '<p><b style="color:' . (['Yellow' => '#c7780a', 'Orange' => '#e0641e', 'Red' => '#d92645'][$w['level']] ?? '#c7780a') . '">' . e($w['level'] . ' ' . $w['kind'] . ' warning') . '</b> · ' . e($w['headline']) . '</p><p>' . nl2br(e($w['description'])) . '</p>'
                    . ($w['expiry'] ? '<p>Valid until ' . e(date_irish($w['expiry'], 'l H:i')) . '.</p>' : '')
                    . '<p><a href="' . e(absolute_url('/alerts')) . '" style="color:#139a5c;font-weight:700">Closures and warnings for your county →</a></p>'
                    . '<p style="color:#59685f;font-size:12px"><a href="' . e(absolute_url('/alerts/unsubscribe/' . $s['token'])) . '" style="color:#59685f">Unsubscribe</a></p>');
                $n++;
            }
        }
        $sent = array_filter($sent, static fn($t) => $t > time() - 14 * 86400);
        file_put_contents($file, json_encode($sent));
        return $n;
    }
}
