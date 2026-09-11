<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Support\Locations;

/**
 * The notices business: death notices and in memoriam, events, local jobs, planning and
 * statutory notices, lost & found pets, and club sport results. One pipeline, one queue.
 * Submissions need no account: the contact confirms by email link, an editor publishes.
 */
final class Notices
{
    public const KINDS = [
        'death' => ['label' => 'Death notice', 'plural' => 'Death notices', 'icon' => 'candle', 'blurb' => 'Funeral arrangements, reposing and family messages, by county.', 'who' => 'Funeral directors and families', 'days' => 30],
        'memoriam' => ['label' => 'In memoriam', 'plural' => 'In memoriam', 'icon' => 'heart', 'blurb' => 'Anniversary and remembrance notices.', 'who' => 'Families', 'days' => 45],
        'event' => ['label' => 'Event', 'plural' => "What's on", 'icon' => 'calendar', 'blurb' => 'Gigs, markets, matches, fundraisers and community events.', 'who' => 'Promoters, venues, clubs', 'days' => 60],
        'job' => ['label' => 'Local job', 'plural' => 'Local jobs', 'icon' => 'briefcase', 'blurb' => 'Vacancies from local employers, cheaper than the big boards.', 'who' => 'Local employers', 'days' => 30],
        'planning' => ['label' => 'Planning notice', 'plural' => 'Planning & statutory', 'icon' => 'building', 'blurb' => 'Planning applications, statutory and public notices.', 'who' => 'Developers, solicitors, councils', 'days' => 42],
        'pet' => ['label' => 'Lost & found pet', 'plural' => 'Lost & found', 'icon' => 'paw', 'blurb' => 'Lost, found and reunited pets.', 'who' => 'Anyone', 'days' => 21],
        'result' => ['label' => 'Club result', 'plural' => 'Club sport', 'icon' => 'trophy', 'blurb' => 'Club fixtures, results and notes from GAA, soccer, rugby and more.', 'who' => 'Club PROs', 'days' => 14],
    ];

    public static function kind(string $k): ?array
    {
        return self::KINDS[$k] ?? null;
    }

    /** Published notices. Filters: kind, county, town, q, upcoming (events), from/to dates. */
    public static function recent(array $f = [], int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT * FROM notices WHERE status='published' AND (expires_at IS NULL OR expires_at>?)";
        $args = [now()];
        if (!empty($f['kind'])) {
            $sql .= ' AND kind=?';
            $args[] = $f['kind'];
        }
        if (!empty($f['county'])) {
            $sql .= ' AND county=?';
            $args[] = $f['county'];
        }
        if (!empty($f['town'])) {
            $sql .= ' AND lower(town)=lower(?)';
            $args[] = $f['town'];
        }
        if (!empty($f['q'])) {
            $sql .= ' AND (title LIKE ? OR body LIKE ? OR town LIKE ? OR contact_org LIKE ?)';
            array_push($args, ...array_fill(0, 4, '%' . $f['q'] . '%'));
        }
        if (!empty($f['upcoming'])) {
            $sql .= ' AND event_at>=?';
            $args[] = gmdate('Y-m-d');
        }
        $order = !empty($f['upcoming']) ? 'event_at ASC' : "(plan='promoted' AND (promoted_until IS NULL OR promoted_until>datetime('now'))) DESC, published_at DESC";
        $rows = Database::all($sql . ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset, $args);
        return array_map([self::class, 'present'], $rows);
    }

    public static function count(array $f = []): int
    {
        $sql = "SELECT COUNT(*) FROM notices WHERE status='published' AND (expires_at IS NULL OR expires_at>?)";
        $args = [now()];
        if (!empty($f['kind'])) {
            $sql .= ' AND kind=?';
            $args[] = $f['kind'];
        }
        if (!empty($f['county'])) {
            $sql .= ' AND county=?';
            $args[] = $f['county'];
        }
        if (!empty($f['q'])) {
            $sql .= ' AND (title LIKE ? OR body LIKE ? OR town LIKE ? OR contact_org LIKE ?)';
            array_push($args, ...array_fill(0, 4, '%' . $f['q'] . '%'));
        }
        return Database::count($sql, $args);
    }

    /** Counts per kind for the notices hub (published only). */
    public static function countsByKind(?string $county = null): array
    {
        $rows = Database::all("SELECT kind, COUNT(*) AS n FROM notices WHERE status='published' AND (expires_at IS NULL OR expires_at>?)" . ($county ? ' AND county=?' : '') . ' GROUP BY kind', $county ? [now(), $county] : [now()]);
        return array_map('intval', array_column($rows, 'n', 'kind'));
    }

    public static function bySlug(string $slug): ?array
    {
        $row = Database::one("SELECT * FROM notices WHERE slug=? AND status='published'", [$slug]);
        return $row ? self::present($row) : null;
    }

    public static function present(array $n): array
    {
        $n['url'] = '/notices/' . $n['kind'] . '/' . $n['slug'];
        $n['extra'] = json_decode((string)($n['extra_json'] ?? '{}'), true) ?: [];
        $n['kind_label'] = self::KINDS[$n['kind']]['label'] ?? ucfirst((string)$n['kind']);
        $n['icon'] = self::KINDS[$n['kind']]['icon'] ?? 'list';
        $n['promoted'] = $n['plan'] === 'promoted' && (!$n['promoted_until'] || $n['promoted_until'] > now());
        unset($n['verify_token'], $n['contact_email'], $n['contact_phone'], $n['extra_json']);
        return $n;
    }

    /**
     * Accept a submission from the public form. Returns the row. The submitter gets an email
     * with a confirmation link; editors see it in the notices queue once confirmed.
     */
    public static function submit(array $in, ?array $user, string $ip): array
    {
        $kind = $in['kind'] ?? '';
        if (!isset(self::KINDS[$kind])) {
            throw new HttpException(400, 'Choose a notice type');
        }
        $title = trim((string)($in['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            throw new HttpException(400, $kind === 'death' ? 'Enter the full name of the deceased' : 'Enter a title');
        }
        $county = trim((string)($in['county'] ?? ''));
        if (!in_array($county, Locations::countyNames(), true)) {
            throw new HttpException(400, 'Choose a county');
        }
        $email = mb_strtolower(trim((string)($in['contact_email'] ?? ($user['email'] ?? ''))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter a contact email so we can confirm the notice');
        }
        RateLimiter::hit($ip . '|notice', 12, 3600, 'Too many notices from this connection. Try again later.');
        $id = uuid();
        $token = bin2hex(random_bytes(20));
        $days = self::KINDS[$kind]['days'];
        $extra = [];
        if ($kind === 'result') {
            foreach (['competition', 'home', 'away', 'home_score', 'away_score', 'club_notes'] as $k) {
                $extra[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, 200);
            }
        }
        if ($kind === 'pet') {
            $extra['pet_status'] = in_array($in['pet_status'] ?? '', ['lost', 'found', 'reunited'], true) ? $in['pet_status'] : 'lost';
        }
        $eventAt = self::dateTime($in['event_at'] ?? '');
        $row = [
            'id' => $id, 'slug' => slugify($title, 60) . '-' . substr($id, 0, 6), 'kind' => $kind,
            'status' => 'review', 'created_at' => now(), 'updated_at' => now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s', ($eventAt ? strtotime($eventAt) + 86400 : time() + $days * 86400)) . '+00:00',
            'title' => mb_substr($title, 0, 160), 'body' => mb_substr(trim((string)($in['body'] ?? '')), 0, 4000),
            'county' => $county, 'town' => mb_substr(trim((string)($in['town'] ?? '')), 0, 80), 'address' => mb_substr(trim((string)($in['address'] ?? '')), 0, 200),
            'date_of_death' => self::date($in['date_of_death'] ?? ''), 'reposing' => mb_substr(trim((string)($in['reposing'] ?? '')), 0, 600),
            'funeral_at' => self::dateTime($in['funeral_at'] ?? ''), 'funeral_venue' => mb_substr(trim((string)($in['funeral_venue'] ?? '')), 0, 200),
            'burial' => mb_substr(trim((string)($in['burial'] ?? '')), 0, 300), 'family_message' => mb_substr(trim((string)($in['family_message'] ?? '')), 0, 600),
            'event_at' => $eventAt, 'event_end' => self::dateTime($in['event_end'] ?? ''), 'venue' => mb_substr(trim((string)($in['venue'] ?? '')), 0, 200),
            'price' => mb_substr(trim((string)($in['price'] ?? '')), 0, 60), 'url' => self::url($in['url'] ?? ''),
            'contact_name' => mb_substr(trim((string)($in['contact_name'] ?? ($user['display_name'] ?? ''))), 0, 100),
            'contact_org' => mb_substr(trim((string)($in['contact_org'] ?? '')), 0, 140), 'contact_email' => $email,
            'contact_phone' => mb_substr(trim((string)($in['contact_phone'] ?? '')), 0, 40),
            'submitted_by' => $user['id'] ?? null, 'verify_token' => $token, 'verified_at' => $user ? now() : null,
            'plan' => 'free', 'extra_json' => json_encode($extra, JSON_UNESCAPED_UNICODE),
        ];
        Database::insert('notices', $row);
        $base = rtrim(Config::get('PUBLIC_BASE_URL', ''), '/');
        if (!$user) {
            Mailer::send($email, 'Confirm your ' . strtolower(self::KINDS[$kind]['label']) . ' on ME News',
                '<p>Thanks for sending a ' . e(strtolower(self::KINDS[$kind]['label'])) . ' for <b>' . e($title) . '</b> (Co. ' . e($county) . ').</p>'
                . '<p>Tap to confirm it came from you. An editor then checks it and it goes live, usually within a few hours:</p>'
                . '<p><a href="' . e($base . '/notices/confirm/' . $token) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700">Confirm this notice</a></p>'
                . '<p style="color:#59685f;font-size:13px">If you did not send this, ignore the email and nothing will be published.</p>');
        }
        return $row;
    }

    public static function confirm(string $token): ?array
    {
        $n = Database::one('SELECT * FROM notices WHERE verify_token=?', [$token]);
        if (!$n) {
            return null;
        }
        if (!$n['verified_at']) {
            Database::query('UPDATE notices SET verified_at=?,updated_at=? WHERE id=?', [now(), now(), $n['id']]);
        }
        return $n;
    }

    /** Editor decision. Publishing a death notice alerts county subscribers. */
    public static function decide(string $id, string $decision, ?string $note, ?string $editorId): array
    {
        $n = Database::one('SELECT * FROM notices WHERE id=?', [$id]);
        if (!$n) {
            throw new HttpException(404, 'Notice not found');
        }
        $status = ['publish' => 'published', 'reject' => 'rejected', 'unpublish' => 'review', 'promote' => $n['status']][$decision] ?? null;
        if ($status === null) {
            throw new HttpException(400, 'Invalid decision');
        }
        $wasPublished = $n['status'] === 'published';
        $plan = $decision === 'promote' ? ($n['plan'] === 'promoted' ? 'free' : 'promoted') : $n['plan'];
        Database::query('UPDATE notices SET status=?,plan=?,promoted_until=?,editorial_note=?,published_at=COALESCE(published_at,?),updated_at=? WHERE id=?',
            [$status, $plan, $plan === 'promoted' ? gmdate('Y-m-d\TH:i:s', time() + 30 * 86400) . '+00:00' : null, $note, $status === 'published' ? now() : null, now(), $id]);
        Audit::log($editorId, 'notice.' . $decision, 'notice', $id, $n['title']);
        if ($status === 'published' && !$wasPublished) {
            self::notify($n);
            if ($n['contact_email']) {
                Mailer::send($n['contact_email'], 'Your notice is live on ME News', '<p><b>' . e($n['title']) . '</b> is now published: <a href="' . e(absolute_url('/notices/' . $n['kind'] . '/' . $n['slug'])) . '">view it here</a>.</p>' . ($note ? '<p>Editor’s note: ' . e($note) . '</p>' : ''));
            }
        } elseif ($status === 'rejected' && $n['contact_email']) {
            Mailer::send($n['contact_email'], 'About your notice on ME News', '<p>We were not able to publish <b>' . e($n['title']) . '</b>.</p>' . ($note ? '<p>' . e($note) . '</p>' : '') . '<p>Reply to this email if you think we got it wrong.</p>');
        }
        return ['ok' => true, 'status' => $status, 'plan' => $plan];
    }

    /** Email subscribers in the notice's county who asked for this kind. */
    private static function notify(array $n): void
    {
        $kindKey = $n['kind'] === 'death' || $n['kind'] === 'memoriam' ? 'deaths' : ($n['kind'] === 'planning' ? 'planning' : null);
        if (!$kindKey) {
            return;
        }
        $rows = Database::all("SELECT email, token, town FROM alert_subscriptions WHERE county=? AND confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND (','||kinds||',') LIKE ?", [$n['county'], '%,' . $kindKey . ',%']);
        $url = absolute_url('/notices/' . $n['kind'] . '/' . $n['slug']);
        foreach ($rows as $s) {
            if ($s['town'] && $n['town'] && strcasecmp($s['town'], $n['town']) !== 0 && $kindKey === 'deaths') {
                continue; // town-scoped subscription
            }
            Mailer::send($s['email'], ($n['kind'] === 'death' ? 'Death notice: ' : 'New notice: ') . $n['title'] . ' · Co. ' . $n['county'],
                '<p><span style="color:#59685f;font-size:12px;letter-spacing:.1em;text-transform:uppercase">' . e(self::KINDS[$n['kind']]['label']) . ' · ' . e(($n['town'] ? $n['town'] . ', ' : '') . 'Co. ' . $n['county']) . '</span></p>'
                . '<h2 style="margin:6px 0 10px;font-size:22px">' . e($n['title']) . '</h2>'
                . ($n['funeral_at'] ? '<p><b>Funeral:</b> ' . e(date_irish($n['funeral_at'], 'l j F, H:i')) . ($n['funeral_venue'] ? ', ' . e($n['funeral_venue']) : '') . '</p>' : '')
                . ($n['reposing'] ? '<p><b>Reposing:</b> ' . e($n['reposing']) . '</p>' : '')
                . '<p>' . nl2br(e(excerpt($n['body'], 400))) . '</p>'
                . '<p><a href="' . e($url) . '" style="color:#139a5c;font-weight:700">Read the full notice →</a></p>'
                . '<p style="color:#59685f;font-size:12px">You asked for ' . e($kindKey) . ' alerts for Co. ' . e($n['county']) . '. <a href="' . e(absolute_url('/alerts/unsubscribe/' . $s['token'])) . '" style="color:#59685f">Unsubscribe</a></p>');
        }
    }

    /** Structured data for an event notice (schema.org Event). */
    public static function eventJsonLd(array $n): ?array
    {
        if ($n['kind'] !== 'event' || !$n['event_at']) {
            return null;
        }
        return [
            '@context' => 'https://schema.org', '@type' => 'Event', 'name' => $n['title'], 'startDate' => $n['event_at'],
            'endDate' => $n['event_end'] ?: $n['event_at'], 'description' => excerpt($n['body'], 300),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'eventStatus' => 'https://schema.org/EventScheduled',
            'location' => ['@type' => 'Place', 'name' => $n['venue'] ?: ($n['town'] ?: 'Co. ' . $n['county']), 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $n['town'] ?: '', 'addressRegion' => 'County ' . $n['county'], 'addressCountry' => 'IE']],
            'organizer' => ['@type' => 'Organization', 'name' => $n['contact_org'] ?: $n['contact_name'] ?: 'Community'],
            'url' => absolute_url($n['url']),
        ] + ($n['price'] ? ['offers' => ['@type' => 'Offer', 'price' => preg_replace('/[^\d.]/', '', $n['price']) ?: '0', 'priceCurrency' => 'EUR', 'url' => $n['url'] ? $n['url'] : absolute_url($n['url'])]] : []);
    }

    public static function expire(): int
    {
        $stmt = Database::query("UPDATE notices SET status='expired' WHERE status='published' AND expires_at IS NOT NULL AND expires_at<?", [now()]);
        return $stmt->rowCount();
    }

    private static function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : null;
    }

    private static function dateTime(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d\TH:i', $ts) : null;
    }

    private static function url(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $v)) {
            $v = 'https://' . $v;
        }
        return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 300) : null;
    }
}
