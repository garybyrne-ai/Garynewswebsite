<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Audit;
use MeNews\Services\Media;
use MeNews\Services\Moderation;
use MeNews\Services\Notifier;
use MeNews\Services\RateLimiter;
use MeNews\Services\Stripe;
use MeNews\Services\TrustEngine;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Locations;

/** Authentication, member accounts, community reporting and billing. */
final class AccountController
{
    // ---------------------------------------------------------------- auth

    public static function register(Request $r): Response
    {
        RateLimiter::hit($r->ip() . '|register', 30, 900, 'Too many attempts. Try again in 15 minutes.');
        $email = mb_strtolower($r->post('email', '', 254));
        $password = $r->rawPost('password');
        if (!is_string($password) || strlen($password) > 1024) {
            throw new HttpException(400, 'Invalid password');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter a valid email address');
        }
        if (strlen($password) < 8) {
            throw new HttpException(400, 'Password must be at least 8 characters');
        }
        $name = $r->post('display_name', '', 80);
        if (mb_strlen($name) < 2) {
            throw new HttpException(400, 'Enter your name');
        }
        if (Database::one('SELECT id FROM users WHERE email=?', [$email])) {
            throw new HttpException(409, 'An account already exists for this email');
        }
        $id = uuid();
        try {
            Database::transaction(static function () use ($id, $email, $password, $name, $r): void {
                Database::insert('users', [
                    'id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $name, 'handle' => self::uniqueHandle($name),
                    'home_town' => $r->post('home_town', '', 80), 'home_county' => $r->post('home_county', '', 80),
                    'accent' => (string)random_int(0, 359), 'created_at' => now(),
                ]);
                Database::insert('subscriptions', ['user_id' => $id, 'email' => $email, 'plan' => 'free', 'status' => 'active', 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            if (Database::one('SELECT id FROM users WHERE email=?', [$email])) {
                throw new HttpException(409, 'An account already exists for this email');
            }
            throw $e;
        }
        $u = Database::one('SELECT * FROM users WHERE id=?', [$id]);
        Audit::log($id, 'register', 'user', $id);
        Notifier::send($id, 'welcome', 'Welcome to ME News', 'Follow your local area and report what is happening around you.');
        return Response::json(['token' => Auth::login($u), 'user' => Auth::publicUser($u)]);
    }

    public static function login(Request $r): Response
    {
        RateLimiter::hit($r->ip() . '|login', 30, 900, 'Too many attempts. Try again in 15 minutes.');
        $email = mb_strtolower($r->post('email', '', 254));
        $password = $r->rawPost('password');
        if (!is_string($password) || strlen($password) > 1024) {
            throw new HttpException(400, 'Invalid password');
        }
        $u = Database::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$u || !Auth::verifyPassword($password, $u['password_hash'])) {
            throw new HttpException(401, 'Incorrect email or password');
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Database::query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        Audit::log($u['id'], 'login', 'user', $u['id']);
        return Response::json(['token' => Auth::login($u), 'user' => Auth::publicUser($u)]);
    }

    public static function logout(Request $r): Response
    {
        $u = Auth::user();
        Auth::logout();
        if ($u) {
            Audit::log($u['id'], 'logout');
        }
        return Response::json(['ok' => true]);
    }

    public static function me(Request $r): Response
    {
        $u = Auth::require();
        $out = Auth::publicUser($u);
        $out['unread'] = Database::count('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']]);
        return Response::json($out);
    }

    public static function profile(Request $r): Response
    {
        $u = Auth::require();
        $name = $r->post('display_name', '', 80);
        if (mb_strlen($name) < 2) {
            throw new HttpException(400, 'Enter your name');
        }
        Database::query('UPDATE users SET display_name=?,home_town=?,home_county=?,bio=? WHERE id=?', [$name, $r->post('home_town', '', 80), $r->post('home_county', '', 80), $r->post('bio', '', 500), $u['id']]);
        Audit::log($u['id'], 'profile.update', 'user', $u['id']);
        return Response::json(Auth::publicUser(Database::one('SELECT * FROM users WHERE id=?', [$u['id']])));
    }

    public static function password(Request $r): Response
    {
        $u = Auth::require();
        $current = $r->rawPost('current_password');
        $new = $r->rawPost('new_password');
        if (!is_string($current) || !is_string($new) || strlen($new) < 8 || strlen($new) > 1024) {
            throw new HttpException(400, 'New password must be at least 8 characters');
        }
        if (!Auth::verifyPassword($current, $u['password_hash'])) {
            throw new HttpException(401, 'Current password is incorrect');
        }
        Database::query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        Audit::log($u['id'], 'password.change', 'user', $u['id']);
        return Response::json(['ok' => true]);
    }

    private static function uniqueHandle(string $name): string
    {
        $base = slugify($name, 24) ?: 'member';
        $handle = $base;
        $i = 1;
        while (Database::one('SELECT id FROM users WHERE handle=?', [$handle])) {
            $handle = $base . '-' . (++$i);
        }
        return $handle;
    }

    // ------------------------------------------------------------- member data

    public static function myReports(Request $r): Response
    {
        $u = Auth::require();
        return Response::json(Database::all('SELECT id,slug,created_at,published_at,title,location_name,county,category,status,verification_label,safety_score,trust_score,editorial_note,views,kind FROM stories WHERE author_user_id=? ORDER BY created_at DESC LIMIT 200', [$u['id']]));
    }

    public static function notifications(Request $r): Response
    {
        $u = Auth::require();
        $rows = Database::all('SELECT n.*, s.slug AS story_slug FROM notifications n LEFT JOIN stories s ON s.id=n.story_id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 100', [$u['id']]);
        return Response::json($rows);
    }

    public static function notificationsRead(Request $r): Response
    {
        $u = Auth::require();
        Database::query('UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
        return Response::json(['ok' => true]);
    }

    public static function follows(Request $r): Response
    {
        $u = Auth::require();
        return Response::json(Database::all('SELECT * FROM follows WHERE user_id=? ORDER BY created_at DESC', [$u['id']]));
    }

    public static function follow(Request $r): Response
    {
        $u = Auth::require();
        $loc = $r->post('location_name', '', 100);
        $county = $r->post('county', '', 80);
        if ($loc === '' && $county === '') {
            throw new HttpException(400, 'Choose a town or county');
        }
        $result = Database::transaction(static function () use ($u, $loc, $county): array {
            if (Database::one("SELECT id FROM follows WHERE user_id=? AND lower(COALESCE(location_name,''))=lower(?) AND lower(COALESCE(county,''))=lower(?)", [$u['id'], $loc, $county])) {
                return ['ok' => true, 'already_following' => true];
            }
            $max = $u['plan'] === 'ME+' ? 10 : 1;
            if (Database::count('SELECT COUNT(*) FROM follows WHERE user_id=?', [$u['id']]) >= $max) {
                throw new HttpException(403, "Your plan allows {$max} followed area" . ($max > 1 ? 's' : '') . '. Upgrade to ME+ for up to 10.');
            }
            Database::insert('follows', ['user_id' => $u['id'], 'location_name' => $loc, 'county' => $county, 'created_at' => now()]);
            return ['ok' => true];
        });
        Audit::log($u['id'], 'follow.add', 'location', $loc ?: $county);
        return Response::json($result);
    }

    public static function unfollow(Request $r, array $p): Response
    {
        $u = Auth::require();
        Database::query('DELETE FROM follows WHERE id=? AND user_id=?', [$p['id'], $u['id']]);
        return Response::json(['ok' => true]);
    }

    // -------------------------------------------------------- community reporting

    /**
     * Community report. No account needed for a first report: a guest gives a name and an
     * email (or mobile); email contacts get a one-tap confirmation link and the report waits
     * for that plus an editor. Photo first, then a location, then the words.
     */
    public static function report(Request $r): Response
    {
        $u = Auth::user();
        $guest = null;
        if ($u) {
            RateLimiter::hit($u['id'] . '|report', 20, 3600, 'You have sent a lot of reports this hour. Please try again later.');
        } else {
            RateLimiter::hit($r->ip() . '|report', 6, 3600, 'Too many reports from this connection this hour. Sign in to send more.');
            $name = $r->post('reporter_name', '', 80);
            $contact = mb_strtolower($r->post('reporter_contact', '', 120));
            if (mb_strlen($name) < 2) {
                throw new HttpException(400, 'Tell us your name');
            }
            $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
            $isPhone = (bool)preg_match('/^\+?[0-9 ()-]{7,20}$/', $contact);
            if (!$isEmail && !$isPhone) {
                throw new HttpException(400, 'Enter an email address or a mobile number so we can confirm it is you');
            }
            $guest = ['name' => $name, 'contact' => $contact, 'email' => $isEmail];
        }
        $id = uuid();
        $body = $r->post('body', '', 6000);
        $title = $r->post('title', '', 180);
        if (mb_strlen(trim($body)) < 12 && mb_strlen($title) < 5) {
            throw new HttpException(400, 'Tell us what you can see, in a line or two');
        }
        if (mb_strlen($title) < 5) {
            // Headline from the first sentence of the description
            $first = preg_split('/(?<=[.!?])\s+/u', trim($body), 2)[0] ?? $body;
            $title = rtrim(excerpt($first, 110), '.');
        }
        $loc = $r->post('location_name', '', 100);
        if ($loc === '') {
            throw new HttpException(400, 'Choose or enter a location');
        }
        $category = $r->post('category', 'Community', 40);
        if (!Categories::valid($category)) {
            $category = 'Community';
        }
        $coords = [];
        foreach (['latitude' => 90, 'longitude' => 180] as $field => $max) {
            $v = $r->post($field);
            if ($v !== '' && (!is_numeric($v) || abs((float)$v) > $max)) {
                throw new HttpException(400, 'Invalid coordinates');
            }
            $coords[$field] = $v === '' ? null : (float)$v;
        }
        $media = null;
        $type = null;
        $inspect = ['exif' => [], 'hash' => null];
        if ($f = $r->file('media')) {
            [$media, $type] = Media::quarantine($f, $id);
            try {
                $inspect = Media::inspect(Media::path($media, 'quarantine'), $type);
            } catch (\Throwable $e) {
                error_log('Media inspect: ' . $e->getMessage());
            }
        }
        $county = $r->post('county', '', 80);
        if ($county === '' && ($coords['latitude'] ?? null) !== null) {
            $county = (string)(\MeNews\Support\Geo::nearest($coords['latitude'], $coords['longitude'])['county'] ?? '');
        }
        if (($coords['latitude'] ?? null) === null && !empty($inspect['exif']['gps'])) {
            // No GPS from the browser but the photo carries one: use it, and tell editors where it came from.
            $coords = ['latitude' => $inspect['exif']['gps']['lat'], 'longitude' => $inspect['exif']['gps']['lng']];
            $inspect['exif']['gps_used_for_pin'] = true;
        }
        $token = $guest ? bin2hex(random_bytes(20)) : null;
        Database::insert('stories', array_merge([
            'id' => $id, 'slug' => Stories::makeSlug($title, $id), 'kind' => 'community',
            'created_at' => now(), 'updated_at' => now(),
            'author_user_id' => $u['id'] ?? null, 'author_name' => $u['display_name'] ?? $guest['name'],
            'title' => $title, 'summary' => excerpt($body, 220), 'body' => $body,
            'location_name' => $loc, 'county' => $county, 'province' => $county ? Locations::provinceFor($county) : $r->post('province', '', 80),
            'local_area' => $r->post('local_area', '', 120), 'category' => $category,
            'media_type' => $type, 'media_original' => $media,
            'status' => 'processing', 'verification_label' => 'Community Report',
            'reporter_contact' => $guest['contact'] ?? null, 'reporter_verified_at' => $u ? now() : null, 'verify_token' => $token,
            'image_hash' => $inspect['hash'], 'exif_json' => $inspect['exif'] ? json_encode($inspect['exif']) : null,
        ], $coords));
        if ($u) {
            Database::query('UPDATE users SET reports_filed=reports_filed+1 WHERE id=?', [$u['id']]);
        }
        Audit::log($u['id'] ?? null, 'report.submit', 'story', $id, $title . ($guest ? ' (guest)' : ''));
        $out = TrustEngine::process($id);
        \MeNews\Services\Clusters::assignIncident($id);
        if ($guest && $guest['email']) {
            \MeNews\Services\Mailer::send($guest['contact'], 'Confirm your report to ME News',
                '<p>Thanks, ' . e($guest['name']) . '. One tap to confirm this came from you:</p><p><b>' . e($title) . '</b><br><span style="color:#59685f">' . e($loc . ($county ? ', Co. ' . $county : '')) . '</span></p>'
                . '<p><a href="' . e(absolute_url('/report/confirm/' . $token)) . '" style="display:inline-block;background:#139a5c;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700">Confirm my report</a></p>'
                . '<p style="color:#59685f;font-size:13px">An editor reads every report before it appears. You’ll hear back either way at this address.</p>');
            $out['message'] = 'Thanks — check your email for a one-tap confirmation. An editor then reads it, and you’ll hear back either way.';
        } elseif ($guest) {
            $out['message'] = 'Thanks — the newsroom has it. An editor may ring or text ' . $guest['contact'] . ' to confirm before it goes live.';
        } else {
            $out['message'] = 'Thanks — your report is with the newsroom (safety ' . (int)($out['safety_score'] ?? 0) . '/100). You’ll hear back either way in your dashboard.';
        }
        return Response::json($out);
    }

    /** One-tap confirmation for guest reports. */
    public static function confirmReport(Request $r, array $p): Response
    {
        $s = Database::one('SELECT id,title,status,reporter_contact,reporter_verified_at FROM stories WHERE verify_token=?', [$p['token']]);
        if ($s && !$s['reporter_verified_at']) {
            Database::query('UPDATE stories SET reporter_verified_at=?,updated_at=? WHERE id=?', [now(), now(), $s['id']]);
            Notifier::staff('report-confirmed', 'Guest confirmed a report: ' . $s['title'], $s['id']);
        }
        return \MeNews\View::page('confirmed', [
            'user' => Auth::user(), 'nav' => Categories::NAV,
            'title' => ($s ? 'Report confirmed' : 'Link not found') . ' — ME News Ireland',
            'heading' => $s ? 'Thanks, that’s confirmed.' : 'That link doesn’t match anything.',
            'text' => $s ? 'An editor will read “' . $s['title'] . '” shortly. If it is published you’ll get an email with the link; if not, you’ll get a note saying why.' : 'The link may have been used already or copied incompletely.',
            'link' => '/', 'linkText' => 'Back to ME News',
        ]);
    }

    /**
     * "I saw this too": independent confirmation. Signed-in members and anonymous readers
     * (one per device via the voter cookie) both count; three earn the Corroborated label.
     */
    public static function confirm(Request $r, array $p): Response
    {
        $u = Auth::user();
        $s = Database::one("SELECT * FROM stories WHERE id=? AND status='published'", [$p['id']]);
        if (!$s) {
            throw new HttpException(404, 'Story not found');
        }
        if ($u && $s['author_user_id'] === $u['id']) {
            throw new HttpException(400, 'You cannot independently confirm your own report');
        }
        RateLimiter::hit($r->ip() . '|confirm', 40, 3600, 'Too many confirmations from this connection.');
        $key = \MeNews\Services\Signal::voterKey($u);
        Database::transaction(static function () use ($u, $s, $r, $key): void {
            if (Database::one('SELECT id FROM confirmations WHERE story_id=? AND (voter_key=? OR (user_id IS NOT NULL AND user_id=?))', [$s['id'], $key, $u['id'] ?? '-'])) {
                throw new HttpException(409, 'You already confirmed this story');
            }
            Database::insert('confirmations', ['story_id' => $s['id'], 'user_id' => $u['id'] ?? null, 'voter_key' => $key, 'created_at' => now(), 'confirmer_name' => $u['display_name'] ?? 'Reader', 'note' => $r->post('note', '', 500)]);
            $n = Stories::confirmations($s['id']);
            $label = $s['kind'] === 'community' && $n >= 3 && $s['verification_label'] === 'Community Report' ? 'Corroborated' : $s['verification_label'];
            Database::query('UPDATE stories SET corroborations=?,trust_score=MIN(95,trust_score+3),verification_label=?,updated_at=? WHERE id=?', [$n, $label, now(), $s['id']]);
        });
        $n = Stories::confirmations($s['id']);
        if ($s['author_user_id']) {
            Notifier::send($s['author_user_id'], 'confirmation', ($n >= 3 ? 'Your report is now Corroborated' : 'Someone saw this too'), $u['display_name'] ?? 'A reader', $s['id']);
        }
        Audit::log($u['id'] ?? null, 'story.confirm', 'story', $s['id']);
        return Response::json(['ok' => true, 'confirmations' => $n, 'corroborated' => $n >= 3]);
    }

    public static function comment(Request $r, array $p): Response
    {
        $u = Auth::require();
        RateLimiter::hit($u['id'] . '|comment', 40, 3600, 'Slow down a little — too many comments this hour.');
        $s = Database::one("SELECT id,author_user_id FROM stories WHERE id=? AND status='published'", [$p['id']]);
        if (!$s) {
            throw new HttpException(404, 'Story not found');
        }
        $body = $r->post('body', '', 1200);
        if (mb_strlen($body) < 2) {
            throw new HttpException(400, 'Comment too short');
        }
        try {
            $mod = Moderation::screen($body);
            $status = $mod['flagged'] ? 'review' : 'published';
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $mod = ['provider' => 'unavailable', 'flagged' => true];
            $status = 'review';
        }
        Database::insert('comments', ['story_id' => $s['id'], 'user_id' => $u['id'], 'created_at' => now(), 'author' => $u['display_name'], 'body' => $body, 'status' => $status, 'moderation_json' => json_encode($mod)]);
        Audit::log($u['id'], 'comment.create', 'story', $s['id'], $status);
        return Response::json(['ok' => true, 'status' => $status, 'comments' => Stories::comments($s['id'])]);
    }

    // ---------------------------------------------------------------- billing

    public static function billingStatus(Request $r): Response
    {
        $u = Auth::require();
        return Response::json(['plan' => $u['plan'], 'stripe_configured' => Stripe::configured(), 'price_label' => \MeNews\Services\Membership::priceLabel(), 'annual_label' => \MeNews\Services\Membership::annualLabel(), 'benefits' => \MeNews\Services\Membership::benefits()]);
    }

    public static function checkout(Request $r): Response
    {
        $u = Auth::require();
        return Response::json(['url' => Stripe::checkoutUrl($u, $r->post('interval', 'month') === 'year' ? 'year' : 'month')]);
    }

    public static function stripeWebhook(Request $r): Response
    {
        return Response::json(Stripe::webhook($r->body(), $r->header('Stripe-Signature')));
    }
}
