<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Audit;
use MeNews\Services\Media;
use MeNews\Services\NewsWire;
use MeNews\Services\Notifier;
use MeNews\Services\TrustEngine;
use MeNews\Support\Categories;
use MeNews\Support\Locations;

/** Newsroom (editor + admin) endpoints. */
final class AdminController
{
    private static function staff(): array
    {
        return Auth::require(['editor', 'admin']);
    }

    public static function summary(Request $r): Response
    {
        self::staff();
        $out = [];
        foreach ([
            'pending' => "stories WHERE status='review'", 'held' => "stories WHERE status='hold'", 'published' => "stories WHERE status='published'",
            'community' => "stories WHERE kind='community' AND status='published'", 'wire' => "stories WHERE kind='wire' AND status='published'",
            'users' => 'users', 'comments_review' => "comments WHERE status='review'", 'ads_review' => "ads WHERE status='review'", 'ads_live' => "ads WHERE status='approved' AND paused=0",
            'notices_review' => "notices WHERE status='review' AND verified_at IS NOT NULL", 'closures_review' => "closures WHERE status='review'",
            'section_check' => "stories WHERE kind='wire' AND status='published' AND category_locked=0 AND suggested_category IS NOT NULL AND suggested_category<>category",
            'takedowns_open' => "takedowns WHERE status='open'",
        ] as $k => $from) {
            $out[$k] = Database::count('SELECT COUNT(*) FROM ' . $from);
        }
        $out['wire_last_refresh'] = NewsWire::lastRefresh();
        $out['wire_stale'] = NewsWire::isStale();
        return Response::json($out);
    }

    public static function stories(Request $r): Response
    {
        self::staff();
        $sql = 'SELECT s.*, u.email AS reporter_email, u.is_verified AS reporter_verified, u.reputation AS reporter_reputation FROM stories s LEFT JOIN users u ON u.id=s.author_user_id';
        $args = [];
        $where = [];
        if ($v = $r->query('status', '', 20)) {
            $where[] = 's.status=?';
            $args[] = $v;
        }
        if ($v = $r->query('kind', '', 20)) {
            $where[] = 's.kind=?';
            $args[] = $v;
        }
        if ($v = $r->query('q', '', 120)) {
            $where[] = '(s.title LIKE ? OR s.author_name LIKE ? OR s.location_name LIKE ?)';
            array_push($args, "%$v%", "%$v%", "%$v%");
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $rows = Database::all($sql . ' ORDER BY s.created_at DESC LIMIT ' . $r->int('limit', 120, 1, 400), $args);
        foreach ($rows as &$s) {
            $s['moderation'] = json_decode($s['moderation_json'] ?? '{}', true) ?: new \stdClass();
            $s['media_url'] = $s['media_original'] ? '/api/admin/stories/' . $s['id'] . '/preview' : null;
            $s['url'] = '/story/' . $s['slug'];
            $exif = json_decode($s['exif_json'] ?? '{}', true) ?: [];
            $s['exif'] = $exif ?: new \stdClass();
            if ($s['kind'] === 'community') {
                $s['public_media_url'] = $s['media_original'] && $s['media_type'] === 'image' ? Media::signedQuarantineUrl($s['id']) : null;
                $s['duplicates'] = $s['image_hash'] ? self::duplicates($s['id'], $s['image_hash']) : [];
                $s['incident_reports'] = $s['cluster_id'] ? Database::all('SELECT id,title,author_name,location_name,status,created_at FROM stories WHERE cluster_id=? AND id<>? ORDER BY created_at', [$s['cluster_id'], $s['id']]) : [];
                $s['gps_distance_km'] = (!empty($exif['gps']) && $s['latitude'] !== null && empty($exif['gps_used_for_pin'])) ? round(\MeNews\Support\Geo::distanceKm((float)$s['latitude'], (float)$s['longitude'], (float)$exif['gps']['lat'], (float)$exif['gps']['lng']), 1) : null;
            }
            unset($s['moderation_json'], $s['exif_json'], $s['verify_token']);
        }
        return Response::json($rows);
    }

    /** Other stories carrying the same or a near-identical image (average-hash distance ≤ 6). */
    private static function duplicates(string $id, string $hash): array
    {
        $out = [];
        foreach (Database::all("SELECT id,slug,title,status,created_at,image_hash FROM stories WHERE image_hash IS NOT NULL AND id<>? AND created_at>? LIMIT 400", [$id, gmdate('Y-m-d\TH:i:s', time() - 90 * 86400) . '+00:00']) as $o) {
            $d = Media::hashDistance($hash, (string)$o['image_hash']);
            if ($d <= 6) {
                $out[] = ['id' => $o['id'], 'title' => $o['title'], 'status' => $o['status'], 'url' => '/story/' . $o['slug'], 'distance' => $d, 'created_at' => $o['created_at']];
            }
        }
        return $out;
    }

    /** Preview quarantined media for editors. */
    public static function preview(Request $r, array $p): Response
    {
        self::staff();
        $s = Database::one('SELECT media_original FROM stories WHERE id=?', [$p['id']]);
        if (!$s || !$s['media_original']) {
            throw new HttpException(404, 'No media');
        }
        Media::stream(Media::path($s['media_original'], 'quarantine'));
    }

    public static function decision(Request $r, array $p): Response
    {
        $u = self::staff();
        $s = Database::one('SELECT * FROM stories WHERE id=?', [$p['id']]);
        if (!$s) {
            throw new HttpException(404, 'Story not found');
        }
        $d = $r->post('decision');
        $label = $r->post('label', $s['verification_label'] ?: ($s['kind'] === 'wire' ? 'Wire' : 'Community Report'));
        $status = ['publish' => 'published', 'hold' => 'hold', 'reject' => 'rejected', 'unpublish' => 'hold'][$d] ?? null;
        if (!$status || !Categories::validLabel($label)) {
            throw new HttpException(400, 'Invalid decision or label');
        }
        $public = $s['media_public'];
        $publishedAt = $s['published_at'];
        if ($status === 'published') {
            if ($s['kind'] === 'community') {
                TrustEngine::gate($s);
                $public = Media::publish($s);
            }
            $publishedAt = $publishedAt ?: now();
        }
        $note = $r->post('note', '', 2000);
        Database::query('UPDATE stories SET status=?,editorial_note=?,verification_label=?,media_public=?,published_at=?,updated_at=? WHERE id=?', [$status, $note, $label, $public, $publishedAt, now(), $s['id']]);
        Audit::log($u['id'], 'story.' . $d, 'story', $s['id'], $label);
        if ($s['kind'] === 'community') {
            Notifier::reportStatus($s, $status, $note);
            if ($status === 'published' && $s['status'] !== 'published' && $s['author_user_id']) {
                Database::query('UPDATE users SET reports_published=reports_published+1 WHERE id=?', [$s['author_user_id']]);
            }
        }
        if ($status === 'published' && $s['status'] !== 'published') {
            Notifier::localAlerts($s);
        }
        return Response::json(['ok' => true, 'status' => $status]);
    }

    public static function rerun(Request $r, array $p): Response
    {
        $u = self::staff();
        if (!Database::one("SELECT id FROM stories WHERE id=? AND kind='community'", [$p['id']])) {
            throw new HttpException(404, 'Report not found');
        }
        $out = TrustEngine::process($p['id']);
        Audit::log($u['id'], 'story.rerun-safety', 'story', $p['id']);
        return Response::json($out);
    }

    /** Edit editorial metadata for any story (section, place, label, featured flag). */
    public static function edit(Request $r, array $p): Response
    {
        $u = self::staff();
        $s = Database::one('SELECT * FROM stories WHERE id=?', [$p['id']]);
        if (!$s) {
            throw new HttpException(404, 'Story not found');
        }
        $category = $r->post('category', $s['category'], 40);
        if (!Categories::valid($category)) {
            throw new HttpException(400, 'Invalid section');
        }
        $label = $r->post('label', $s['verification_label'], 40);
        if (!Categories::validLabel($label)) {
            throw new HttpException(400, 'Invalid label');
        }
        $county = $r->post('county', (string)$s['county'], 60);
        $featured = (int)($r->post('is_featured', $s['is_featured'] ? '1' : '0') === '1');
        if ($featured) {
            Database::query('UPDATE stories SET is_featured=0 WHERE is_featured=1');
        }
        Database::query('UPDATE stories SET category=?,county=?,province=?,location_name=?,verification_label=?,is_featured=?,category_locked=?,suggested_category=?,updated_at=? WHERE id=?', [
            $category, $county, $county !== '' ? Locations::provinceFor($county) : null,
            $r->post('location_name', (string)$s['location_name'], 100), $label, $featured, $category !== $s['category'] ? 1 : (int)$s['category_locked'], $category, now(), $s['id'],
        ]);
        Audit::log($u['id'], 'story.edit', 'story', $s['id'], "$category/$county/$label/featured=$featured");
        return Response::json(['ok' => true]);
    }

    public static function users(Request $r): Response
    {
        $u = self::staff();
        $sql = 'SELECT id,email,display_name,handle,role,is_verified,plan,title,desk,home_town,home_county,reputation,created_at,last_seen_at FROM users';
        $args = [];
        if ($v = $r->query('q', '', 120)) {
            $sql .= ' WHERE email LIKE ? OR display_name LIKE ?';
            $args = ["%$v%", "%$v%"];
        }
        $rows = Database::all($sql . ' ORDER BY created_at DESC LIMIT 300', $args);
        if ($u['role'] !== 'admin') {
            // Editors moderate people, admins manage accounts: only admins see full email addresses.
            foreach ($rows as &$row) {
                $row['email'] = preg_replace_callback('/^(.).*?(.?)@/', static fn($m) => $m[1] . '•••' . $m[2] . '@', (string)$row['email']);
            }
        }
        return Response::json($rows);
    }

    public static function updateUser(Request $r, array $p): Response
    {
        $u = Auth::require(['admin']);
        $role = $r->post('role', 'member');
        if (!in_array($role, ['admin', 'editor', 'contributor', 'member'], true)) {
            throw new HttpException(400, 'Invalid role');
        }
        if ($u['id'] === $p['id'] && $role !== 'admin') {
            throw new HttpException(400, 'You cannot remove your own administrator access');
        }
        $target = Database::one('SELECT id,role,plan FROM users WHERE id=?', [$p['id']]);
        if (!$target) {
            throw new HttpException(404, 'User not found');
        }
        $verified = (int)($r->post('is_verified') === '1');
        $rep = max(0, min(100, (int)$r->post('reputation', '50')));
        $plan = $r->post('plan', $target['plan'], 10) === 'ME+' ? 'ME+' : 'free';
        Database::query('UPDATE users SET role=?,is_verified=?,reputation=?,title=?,desk=?,plan=? WHERE id=?', [$role, $verified, $rep, $r->post('title', '', 80) ?: null, $r->post('desk', '', 40) ?: null, $plan, $p['id']]);
        if ($plan !== $target['plan']) {
            Audit::log($u['id'], 'user.plan', 'user', $p['id'], $plan . ' (complimentary, set by admin)');
        }
        if ($role !== $target['role']) {
            // A role change takes effect everywhere at once: sign the account out of every device.
            Database::query('DELETE FROM sessions WHERE user_id=?', [$p['id']]);
        }
        Audit::log($u['id'], 'user.update', 'user', $p['id'], "$role/$verified/$rep/$plan");
        return Response::json(['ok' => true]);
    }

    /** Admin creates an account (a contributor, an editor, or a member who cannot self-register). */
    public static function createUser(Request $r): Response
    {
        $u = Auth::require(['admin']);
        $email = mb_strtolower($r->post('email', '', 254));
        $name = $r->post('display_name', '', 80);
        $password = $r->rawPost('password');
        $role = $r->post('role', 'member', 20);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter a valid email address');
        }
        if (mb_strlen($name) < 2) {
            throw new HttpException(400, 'Enter the person\'s name');
        }
        if (!in_array($role, ['admin', 'editor', 'contributor', 'member'], true)) {
            throw new HttpException(400, 'Invalid role');
        }
        if (Database::one('SELECT id FROM users WHERE email=?', [$email])) {
            throw new HttpException(409, 'An account with that email already exists');
        }
        $generated = !is_string($password) || $password === '';
        if ($generated) {
            $password = bin2hex(random_bytes(6));
        } elseif (strlen($password) < 8 || strlen($password) > 1024) {
            throw new HttpException(400, 'Temporary password must be at least 8 characters');
        }
        $county = $r->post('home_county', '', 80);
        $id = uuid();
        Database::insert('users', [
            'id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $name,
            'handle' => AccountController::uniqueHandle($name), 'role' => $role, 'title' => $r->post('title', '', 80) ?: null, 'desk' => $r->post('desk', '', 40) ?: null,
            'home_county' => in_array($county, Locations::countyNames(), true) ? $county : null, 'plan' => $r->post('plan', 'free', 10) === 'ME+' ? 'ME+' : 'free',
            'accent' => (string)random_int(0, 359), 'created_at' => now(),
        ]);
        Database::insert('subscriptions', ['user_id' => $id, 'email' => $email, 'plan' => 'free', 'status' => 'active', 'created_at' => now()]);
        Audit::log($u['id'], 'user.create', 'user', $id, "$email as $role");
        \MeNews\Services\Mailer::send($email, 'Your ME News account', '<p>Hi ' . e($name) . ',</p><p>' . e($u['display_name']) . ' has created an ME News account for you' . ($role !== 'member' ? ' as <b>' . e($role) . '</b>' : '') . '.</p><p>Sign in at <a href="' . e(absolute_url('/?auth=signin')) . '">' . e(absolute_url('/')) . '</a> with this address and the temporary password your editor gives you, then change it under <b>Security</b> in your dashboard.</p>');
        return Response::json(['ok' => true, 'id' => $id, 'temporary_password' => $generated ? $password : null]);
    }

    /** Admin deletes an account. Reports stay published under their byline text; everything personal goes. */
    public static function deleteUser(Request $r, array $p): Response
    {
        $u = Auth::require(['admin']);
        if ($u['id'] === $p['id']) {
            throw new HttpException(400, 'You cannot delete your own account from here');
        }
        $target = Database::one('SELECT id,email,role FROM users WHERE id=?', [$p['id']]);
        if (!$target) {
            throw new HttpException(404, 'User not found');
        }
        if ($target['role'] === 'admin' && (int)Database::value("SELECT COUNT(*) FROM users WHERE role='admin'") <= 1) {
            throw new HttpException(400, 'That is the last administrator');
        }
        if (mb_strtolower($r->post('confirm', '', 254)) !== mb_strtolower($target['email'])) {
            throw new HttpException(400, 'Type the account\'s email address to confirm');
        }
        Database::pdo()->beginTransaction();
        try {
            // Tables without a foreign key back to users are detached by hand; the rest cascade or null out.
            Database::query("DELETE FROM ads WHERE user_id=? AND is_house=0", [$target['id']]);
            Database::query("UPDATE ads SET user_id=NULL WHERE user_id=?", [$target['id']]);
            Database::query('UPDATE alert_subscriptions SET user_id=NULL WHERE user_id=?', [$target['id']]);
            Database::query('UPDATE votes SET user_id=NULL WHERE user_id=?', [$target['id']]);
            Database::query('DELETE FROM users WHERE id=?', [$target['id']]);
            Database::pdo()->commit();
        } catch (\Throwable $e) {
            Database::pdo()->rollBack();
            throw $e;
        }
        Audit::log($u['id'], 'user.delete', 'user', $target['id'], $target['email']);
        return Response::json(['ok' => true]);
    }

    public static function comments(Request $r): Response
    {
        self::staff();
        return Response::json(Database::all("SELECT c.*, s.title, s.slug FROM comments c JOIN stories s ON s.id=c.story_id WHERE c.status='review' ORDER BY c.created_at DESC LIMIT 200"));
    }

    public static function itemDecision(Request $r, array $p): Response
    {
        $u = self::staff();
        $table = $p['table'];
        $d = $r->post('decision');
        $allowed = $table === 'ads' ? ['approve' => 'approved', 'reject' => 'rejected'] : ['publish' => 'published', 'reject' => 'rejected'];
        if (!isset($allowed[$d])) {
            throw new HttpException(400, 'Invalid decision');
        }
        if (!Database::one("SELECT id FROM {$table} WHERE id=?", [$p['id']])) {
            throw new HttpException(404, 'Item not found');
        }
        Database::query("UPDATE {$table} SET status=? WHERE id=?", [$allowed[$d], $p['id']]);
        Audit::log($u['id'], $table . '.' . $d, $table, $p['id']);
        return Response::json(['ok' => true]);
    }

    public static function refreshLocations(Request $r): Response
    {
        $u = Auth::require(['admin']);
        set_time_limit(300);
        $rows = Locations::refreshOfficial();
        Audit::log($u['id'], 'locations.refresh', 'locations', 'ireland', (string)count($rows));
        return Response::json(['ok' => true, 'count' => count($rows), 'source' => 'CSO/Tailte Éireann 2022']);
    }

    public static function refreshWire(Request $r): Response
    {
        $u = self::staff();
        set_time_limit(300);
        $summary = NewsWire::refresh(true);
        Audit::log($u['id'], 'wire.refresh', 'wire', '', 'inserted=' . $summary['inserted']);
        $summary['last_refresh'] = NewsWire::lastRefresh();
        return Response::json($summary);
    }

    public static function wireRuns(Request $r): Response
    {
        self::staff();
        $u = Auth::user();
        $key = \MeNews\Config::get('CRON_KEY');
        return Response::json([
            'sources' => NewsWire::sources(),
            'runs' => Database::all('SELECT * FROM wire_runs ORDER BY id DESC LIMIT 60'),
            'last_refresh' => NewsWire::lastRefresh(),
            'refresh_count' => (int)Database::setting('wire_refresh_count', '0'),
            'interval_minutes' => \MeNews\Config::int('WIRE_REFRESH_MINUTES', 20),
            'auto_refresh' => \MeNews\Config::bool('WIRE_AUTO_REFRESH', true),
            'background_capable' => function_exists('fastcgi_finish_request'),
            'cron_url' => $u && $u['role'] === 'admin' && $key !== '' ? absolute_url('/cron/wire?key=' . rawurlencode($key)) : null,
            'cron_cli' => 'php ' . ME_ROOT . '/scripts/fetch-news.php --if-stale',
        ]);
    }

    /** Wire stories whose suggested section differs from the stored one (editor override queue). */
    public static function sectionCheck(Request $r): Response
    {
        self::staff();
        \MeNews\Services\NewsWire::resuggest(300);
        $rows = Database::all("SELECT id,slug,title,category,suggested_category,source_name,county,COALESCE(published_at,created_at) AS t FROM stories WHERE kind='wire' AND status='published' AND category_locked=0 AND suggested_category IS NOT NULL AND suggested_category<>category ORDER BY t DESC LIMIT 150");
        foreach ($rows as &$x) {
            $x['url'] = '/story/' . $x['slug'];
        }
        return Response::json($rows);
    }

    /** One-click re-file: accept the suggestion (or set any section) and lock it against reclassification. */
    public static function refile(Request $r, array $p): Response
    {
        $u = self::staff();
        $s = Database::one('SELECT id,category,suggested_category FROM stories WHERE id=?', [$p['id']]);
        if (!$s) {
            throw new HttpException(404, 'Story not found');
        }
        $to = $r->post('category', '', 40) ?: (string)$s['suggested_category'];
        if (!Categories::valid($to)) {
            throw new HttpException(400, 'Invalid section');
        }
        $keep = $r->post('keep') === '1';
        Database::query('UPDATE stories SET category=?,suggested_category=?,category_locked=1,updated_at=? WHERE id=?', [$keep ? $s['category'] : $to, $keep ? $s['category'] : $to, now(), $s['id']]);
        Audit::log($u['id'], $keep ? 'story.keep-section' : 'story.refile', 'story', $s['id'], $s['category'] . '→' . ($keep ? $s['category'] : $to));
        return Response::json(['ok' => true, 'category' => $keep ? $s['category'] : $to]);
    }

    // ------------------------------------------------------------ local layer

    public static function notices(Request $r): Response
    {
        self::staff();
        $status = $r->query('status', 'review', 20);
        $sql = 'SELECT * FROM notices' . ($status !== 'all' ? ' WHERE status=?' : '') . ' ORDER BY created_at DESC LIMIT 200';
        $rows = Database::all($sql, $status !== 'all' ? [$status] : []);
        foreach ($rows as &$n) {
            $n['url'] = '/notices/' . $n['kind'] . '/' . $n['slug'];
            $n['extra'] = json_decode((string)($n['extra_json'] ?? '{}'), true) ?: new \stdClass();
            unset($n['verify_token'], $n['extra_json']);
        }
        return Response::json($rows);
    }

    public static function noticeDecision(Request $r, array $p): Response
    {
        $u = self::staff();
        return Response::json(\MeNews\Services\Notices::decide($p['id'], $r->post('decision', '', 20), $r->post('note', '', 1000) ?: null, $u['id']));
    }

    public static function closures(Request $r): Response
    {
        self::staff();
        return Response::json(Database::all('SELECT id,created_at,status,school,county,town,closed_on,reopens_on,reason,contact_name,contact_role,contact_email,verified_at,published_at FROM closures ORDER BY created_at DESC LIMIT 200'));
    }

    public static function closureDecision(Request $r, array $p): Response
    {
        $u = self::staff();
        $status = ['publish' => 'published', 'reject' => 'rejected', 'unpublish' => 'review'][$r->post('decision', '', 20)] ?? null;
        if (!$status) {
            throw new HttpException(400, 'Invalid decision');
        }
        Database::query('UPDATE closures SET status=?,published_at=COALESCE(published_at,?) WHERE id=?', [$status, $status === 'published' ? now() : null, $p['id']]);
        Audit::log($u['id'], 'closure.' . $status, 'closure', $p['id']);
        return Response::json(['ok' => true, 'status' => $status]);
    }

    /** Site settings editable by administrators (prices, WhatsApp numbers, wire mode, ownership text). */
    public static function settings(Request $r): Response
    {
        Auth::require(['admin']);
        $keys = self::settingKeys();
        $out = [];
        foreach ($keys as $k => $meta) {
            $out[$k] = ['value' => Database::setting($k, (string)$meta['default']), 'label' => $meta['label'], 'group' => $meta['group'], 'type' => $meta['type'], 'options' => $meta['options'] ?? null];
        }
        return Response::json($out);
    }

    public static function saveSettings(Request $r): Response
    {
        $u = Auth::require(['admin']);
        $keys = self::settingKeys();
        $saved = [];
        foreach ($_POST as $k => $v) {
            if (!isset($keys[$k]) || !is_string($v)) {
                continue;
            }
            $v = trim($v);
            $meta = $keys[$k];
            if ($meta['type'] === 'cents') {
                $v = (string)max(0, (int)round((float)str_replace(',', '.', $v) * 100));
            } elseif ($meta['type'] === 'int') {
                $v = (string)max(0, (int)$v);
            } elseif ($meta['type'] === 'select' && !in_array($v, $meta['options'], true)) {
                continue;
            }
            Database::setSetting($k, mb_substr($v, 0, 500));
            $saved[] = $k;
        }
        Audit::log($u['id'], 'settings.save', 'settings', '', implode(',', $saved));
        return Response::json(['ok' => true, 'saved' => $saved]);
    }

    private static function settingKeys(): array
    {
        $keys = [
            'wire_mode' => ['label' => 'Wire display', 'group' => 'Wire', 'type' => 'select', 'options' => ['clustered', 'full', 'links'], 'default' => 'clustered'],
            'wire_images' => ['label' => 'Show publisher images on wire cards', 'group' => 'Wire', 'type' => 'select', 'options' => ['1', '0'], 'default' => '1'],
            'plus_price_cents' => ['label' => 'ME+ monthly price (€)', 'group' => 'Membership', 'type' => 'cents', 'default' => '399'],
            'plus_annual_cents' => ['label' => 'ME+ annual price (€)', 'group' => 'Membership', 'type' => 'cents', 'default' => '3900'],
            'ads_price_cents' => ['label' => 'Advertising monthly price (€)', 'group' => 'Advertising', 'type' => 'cents', 'default' => '2500'],
            'ads_trial_days' => ['label' => 'Advertising free trial (days)', 'group' => 'Advertising', 'type' => 'int', 'default' => '7'],
            'whatsapp_number' => ['label' => 'WhatsApp reporting number (national)', 'group' => 'Community', 'type' => 'text', 'default' => ''],
            'whatsapp_channel' => ['label' => 'WhatsApp channel link (national)', 'group' => 'Community', 'type' => 'text', 'default' => ''],
            'contact_email' => ['label' => 'Editorial contact email', 'group' => 'Ownership', 'type' => 'text', 'default' => ''],
            'owner_name' => ['label' => 'Owner / publisher name', 'group' => 'Ownership', 'type' => 'text', 'default' => 'Gary Byrne'],
            'owner_company' => ['label' => 'Publishing company', 'group' => 'Ownership', 'type' => 'text', 'default' => 'ME News Ireland'],
            'owner_address' => ['label' => 'Registered address', 'group' => 'Ownership', 'type' => 'text', 'default' => 'Ireland'],
        ];
        foreach (Locations::countyNames() as $c) {
            $keys['whatsapp_' . slugify($c)] = ['label' => $c . ' WhatsApp reporting number', 'group' => 'WhatsApp by county', 'type' => 'text', 'default' => ''];
            $keys['whatsapp_channel_' . slugify($c)] = ['label' => $c . ' WhatsApp channel link', 'group' => 'WhatsApp channels by county', 'type' => 'text', 'default' => ''];
        }
        return $keys;
    }

    public static function audit(Request $r): Response
    {
        Auth::require(['admin']);
        return Response::json(Database::all('SELECT a.*, u.display_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 300'));
    }
}
