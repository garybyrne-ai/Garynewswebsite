<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Support\Locations;

/**
 * ME Ads — self-serve local advertising.
 *
 * Advertisers design an ad in the browser (structured spec, rendered server-side so no
 * arbitrary HTML ever reaches the page), submit it for editorial approval, run a free trial,
 * then subscribe monthly through Stripe or PayPal. House ads (the publisher's own) run
 * without billing. Ads appear in sidebars (card) and as banners on the home page, section
 * pages and inside articles. The kids' section is always ad-free.
 */
final class Ads
{
    public const TEMPLATES = [
        'aurora' => ['label' => 'Aurora', 'bg1' => '#0b2a3a', 'bg2' => '#12604f', 'fg' => '#eef7f4', 'ac' => '#2ef2a8'],
        'bold' => ['label' => 'Bold', 'bg1' => '#ff6a1f', 'bg2' => '#c8102e', 'fg' => '#ffffff', 'ac' => '#111111'],
        'clean' => ['label' => 'Clean', 'bg1' => '#ffffff', 'bg2' => '#f2f5fa', 'fg' => '#0a1a33', 'ac' => '#0b4fd1'],
        'night' => ['label' => 'Night', 'bg1' => '#0a0e18', 'bg2' => '#1b2340', 'fg' => '#f2f4fa', 'ac' => '#3ddc84'],
        'paper' => ['label' => 'Paper', 'bg1' => '#f7f1e3', 'bg2' => '#efe6d2', 'fg' => '#1c1813', 'ac' => '#b8321f'],
        'photo' => ['label' => 'Photo', 'bg1' => '#05070d', 'bg2' => '#05070d', 'fg' => '#ffffff', 'ac' => '#ffb547'],
    ];
    public const PLACEMENTS = ['both', 'sidebar', 'banner'];
    public const LIMITS = ['headline' => 64, 'subline' => 120, 'cta' => 22, 'badge' => 18, 'business_name' => 60];

    // ------------------------------------------------------------- settings

    public static function price(): int
    {
        return max(0, (int)Database::setting('ads_price_cents', '2500'));
    }

    public static function trialDays(): int
    {
        return max(0, (int)Database::setting('ads_trial_days', '7'));
    }

    public static function currency(): string
    {
        return strtoupper(Database::setting('ads_currency', 'EUR') ?: 'EUR');
    }

    public static function priceLabel(?int $cents = null): string
    {
        $cents ??= self::price();
        $symbol = ['EUR' => '€', 'GBP' => '£', 'USD' => '$'][self::currency()] ?? self::currency() . ' ';
        return $symbol . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2) . '/month';
    }

    public static function pricing(): array
    {
        return [
            'price_cents' => self::price(), 'price_label' => self::priceLabel(), 'currency' => self::currency(),
            'trial_days' => self::trialDays(), 'stripe' => Stripe::adsConfigured(), 'paypal' => PayPal::configured(), 'paypal_mode' => Config::get('PAYPAL_MODE', 'sandbox'),
            'templates' => self::TEMPLATES, 'limits' => self::LIMITS,
            'packages' => AdPackages::all(), 'tiers' => AdPackages::TIERS,
        ];
    }

    public static function updateSettings(int $cents, int $trialDays, string $currency): void
    {
        Database::setSetting('ads_price_cents', (string)max(100, $cents));
        Database::setSetting('ads_trial_days', (string)max(0, min(60, $trialDays)));
        Database::setSetting('ads_currency', in_array($currency, ['EUR', 'GBP', 'USD'], true) ? $currency : 'EUR');
    }

    // ------------------------------------------------------------- lookup

    public static function find(string $id): ?array
    {
        return Database::one('SELECT * FROM ads WHERE id=?', [$id]);
    }

    public static function forUser(string $userId): array
    {
        return array_map([self::class, 'present'], Database::all('SELECT * FROM ads WHERE user_id=? ORDER BY created_at DESC', [$userId]));
    }

    public static function all(string $status = ''): array
    {
        $sql = 'SELECT a.*, u.email AS owner_email, u.display_name AS owner_name FROM ads a LEFT JOIN users u ON u.id=a.user_id';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE a.status=?';
            $args[] = $status;
        }
        return array_map([self::class, 'present'], Database::all($sql . ' ORDER BY a.created_at DESC LIMIT 500', $args));
    }

    // ------------------------------------------------------------- lifecycle

    /** True when the ad may be shown right now: approved, not paused, and (unless house) with impressions left. */
    public static function isLive(array $ad): bool
    {
        if ($ad['status'] !== 'approved' || (int)$ad['paused'] === 1) {
            return false;
        }
        if ((int)$ad['is_house'] === 1) {
            return true;
        }
        if (($ad['plan_status'] ?? '') === 'credits') {
            return AdOrders::remaining($ad['id']) > 0;
        }
        // Legacy subscription/trial adverts keep running until their period ends.
        $now = now();
        return match ($ad['plan_status']) {
            'trial' => ($ad['trial_ends_at'] ?? '') > $now,
            'active', 'past_due', 'comped' => ($ad['current_period_end'] ?? '') > $now,
            default => false,
        };
    }

    /** Human label + tone for dashboards. */
    public static function stateLabel(array $ad): array
    {
        if ($ad['status'] === 'draft') {
            return ['Draft', 'muted'];
        }
        if ($ad['status'] === 'review') {
            return ['Awaiting review', 'warn'];
        }
        if ($ad['status'] === 'rejected') {
            return ['Not approved', 'bad'];
        }
        if ((int)$ad['paused'] === 1) {
            return ['Paused', 'muted'];
        }
        if ((int)$ad['is_house'] === 1) {
            return ['Live · house ad', 'good'];
        }
        if (($ad['plan_status'] ?? '') === 'credits') {
            $left = AdOrders::remaining($ad['id']);
            if ($left > 0) {
                return ['Live · ' . number_format($left) . ' impressions left', 'good'];
            }
            $paid = Database::count("SELECT COUNT(*) FROM ad_orders WHERE ad_id=? AND status='paid'", [$ad['id']]);
            return $paid ? ['Approved · impressions ready', 'good'] : ['Finished · buy more impressions to resume', 'bad'];
        }
        $now = now();
        switch ($ad['plan_status']) {
            case 'trial':
                if (($ad['trial_ends_at'] ?? '') > $now) {
                    $days = max(0, (int)ceil((strtotime($ad['trial_ends_at']) - time()) / 86400));
                    return ['Live · free trial, ' . $days . ' day' . ($days === 1 ? '' : 's') . ' left', 'good'];
                }
                return ['Trial ended · subscribe to resume', 'bad'];
            case 'active':
                return [($ad['current_period_end'] ?? '') > $now ? 'Live · renews ' . date_irish($ad['current_period_end'], 'j M') : 'Payment due', ($ad['current_period_end'] ?? '') > $now ? 'good' : 'bad'];
            case 'past_due':
                return ['Payment failed · update your card', 'bad'];
            case 'comped':
                return [($ad['current_period_end'] ?? '') > $now ? 'Live · until ' . date_irish($ad['current_period_end'], 'j M Y') : 'Expired', ($ad['current_period_end'] ?? '') > $now ? 'good' : 'bad'];
            case 'cancelled':
                return [($ad['current_period_end'] ?? '') > $now ? 'Cancelled · runs until ' . date_irish($ad['current_period_end'], 'j M') : 'Cancelled', 'muted'];
            default:
                return ['Approved · attach a package to go live', 'warn'];
        }
    }

    public static function present(array $ad): array
    {
        [$label, $tone] = self::stateLabel($ad);
        $ad['design'] = json_decode($ad['design_json'] ?? '{}', true) ?: [];
        $ad['live'] = self::isLive($ad);
        $ad['state_label'] = $label;
        $ad['state_tone'] = $tone;
        $ad['logo_url'] = $ad['logo_path'] ? '/media/ad/' . $ad['id'] . '/logo?v=' . substr(md5((string)$ad['updated_at']), 0, 6) : null;
        $ad['image_url'] = $ad['image_path'] ? '/media/ad/' . $ad['id'] . '/image?v=' . substr(md5((string)$ad['updated_at']), 0, 6) : null;
        $ad['ctr'] = $ad['impressions'] > 0 ? round($ad['clicks'] / $ad['impressions'] * 100, 2) : 0.0;
        $ad['is_house'] = (bool)$ad['is_house'];
        $ad['paused'] = (bool)$ad['paused'];
        $ad['can_subscribe'] = false;
        $ad['tier'] = $ad['tier'] ?? 'sidebar';
        $ad['tier_label'] = AdPackages::TIERS[$ad['tier']]['label'] ?? $ad['tier'];
        $ad['impressions_left'] = $ad['is_house'] ? null : AdOrders::remaining($ad['id']);
        $ad['orders'] = $ad['is_house'] ? [] : array_map([AdOrders::class, 'present'], Database::all("SELECT * FROM ad_orders WHERE ad_id=? AND status IN ('paid','running','completed') ORDER BY created_at DESC LIMIT 20", [$ad['id']]));
        unset($ad['design_json']);
        return $ad;
    }

    /** Validate + store an ad from designer input. Returns the saved row. */
    public static function save(?string $id, array $post, array $files, ?array $user, bool $house = false): array
    {
        $existing = $id ? self::find($id) : null;
        if ($id && !$existing) {
            throw new HttpException(404, 'Advert not found');
        }
        if ($existing && !$house && ($existing['user_id'] !== ($user['id'] ?? null))) {
            throw new HttpException(403, 'This advert belongs to another account');
        }
        if ($existing && !$house && (int)$existing['is_house'] === 1) {
            throw new HttpException(403, 'House adverts are managed by the newsroom');
        }
        // The designer unlocks with a paid package: a new advert must be backed by an unused order.
        $order = null;
        if (!$house && !$existing) {
            $orderId = (string)($post['order_id'] ?? '');
            $credits = AdOrders::credits((string)($user['id'] ?? ''));
            foreach ($credits as $c) {
                if ($orderId === '' || $c['id'] === $orderId) {
                    $order = $c;
                    break;
                }
            }
            if (!$order) {
                throw new HttpException(402, 'Buy an advertising package first — it unlocks the designer.');
            }
        }
        $get = static fn(string $k, int $max) => mb_substr(trim((string)($post[$k] ?? '')), 0, $max);
        $business = $get('business_name', self::LIMITS['business_name']);
        $headline = $get('title', self::LIMITS['headline']);
        $subline = $get('body', self::LIMITS['subline']);
        $cta = $get('cta', self::LIMITS['cta']) ?: 'Learn more';
        $badge = $get('badge', self::LIMITS['badge']);
        $url = $get('url', 500);
        if ($business === '') {
            throw new HttpException(400, 'Enter your business name');
        }
        if (mb_strlen($headline) < 4) {
            throw new HttpException(400, 'Write a headline (at least 4 characters)');
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
            throw new HttpException(400, 'Enter the full web address people should land on (https://…)');
        }
        $placement = in_array($post['placement'] ?? '', self::PLACEMENTS, true) ? $post['placement'] : 'both';
        if (!$house) {
            // Placement is decided by the package tier, never by the advertiser's form.
            $placement = 'both';
        }
        $county = $get('target_county', 60);
        if ($county !== '' && !in_array($county, Locations::countyNames(), true)) {
            $county = '';
        }
        $design = self::sanitizeDesign($post);
        $now = now();
        $row = [
            'business_name' => $business, 'title' => $headline, 'body' => $subline, 'cta' => $cta, 'badge' => $badge, 'url' => $url,
            'target_county' => $county, 'target_town' => $get('target_town', 80), 'placement' => $placement,
            'design_json' => json_encode($design, JSON_UNESCAPED_UNICODE), 'updated_at' => $now,
        ];
        if ($existing) {
            // Editing a live/reviewed ad sends it back through review (house ads excepted).
            if (!$house && in_array($existing['status'], ['approved', 'review', 'rejected'], true)) {
                $row['status'] = 'review';
            }
            Database::update('ads', $row, 'id=?', [$id]);
        } else {
            $id = uuid();
            Database::insert('ads', $row + [
                'id' => $id, 'user_id' => $user['id'] ?? null, 'created_at' => $now,
                'status' => $house ? 'approved' : 'draft', 'is_house' => $house ? 1 : 0, 'approved_at' => $house ? $now : null,
                'plan_status' => $house ? 'none' : 'credits', 'tier' => $house ? 'premium' : $order['tier'],
            ]);
            if ($order) {
                AdOrders::attach($order['id'], $id);
            }
        }
        foreach (['logo', 'image'] as $kind) {
            if (isset($files[$kind]) && ($files[$kind]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $path = self::storeImage($files[$kind], $id, $kind);
                Database::query("UPDATE ads SET {$kind}_path=? WHERE id=?", [$path, $id]);
            }
            if (($post['remove_' . $kind] ?? '') === '1') {
                Database::query("UPDATE ads SET {$kind}_path=NULL WHERE id=?", [$id]);
            }
        }
        return self::find($id);
    }

    public static function sanitizeDesign(array $in): array
    {
        $template = isset(self::TEMPLATES[$in['template'] ?? '']) ? $in['template'] : 'aurora';
        $defaults = self::TEMPLATES[$template];
        $hex = static fn(string $k, string $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in[$k] ?? '')) ? strtolower($in[$k]) : $d;
        return [
            'template' => $template,
            'bg1' => $hex('bg1', $defaults['bg1']), 'bg2' => $hex('bg2', $defaults['bg2']),
            'fg' => $hex('fg', $defaults['fg']), 'ac' => $hex('ac', $defaults['ac']),
            'shape' => in_array($in['shape'] ?? '', ['orb', 'grid', 'stripes', 'none'], true) ? $in['shape'] : 'orb',
            'align' => in_array($in['align'] ?? '', ['left', 'center'], true) ? $in['align'] : 'left',
        ];
    }

    public static function storeImage(array $f, string $adId, string $kind): string
    {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new HttpException(400, 'Image upload failed');
        }
        if ((int)$f['size'] > 4 * 1048576) {
            throw new HttpException(413, 'Images must be under 4 MB');
        }
        $mime = (string)mime_content_type($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) {
            throw new HttpException(400, 'Use a JPG, PNG or WebP image');
        }
        $dir = Config::storage() . '/uploads/ads';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        foreach (glob($dir . '/' . $adId . '-' . $kind . '.*') ?: [] as $old) {
            @unlink($old);
        }
        // Re-encode through GD: strips metadata and guarantees the stored file is a plain image.
        $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if (!$im) {
            throw new HttpException(400, 'That image could not be read');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $max = $kind === 'logo' ? 512 : 1600;
        if ($w > $max || $h > $max) {
            $scale = $max / max($w, $h);
            $resized = imagecreatetruecolor((int)round($w * $scale), (int)round($h * $scale));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $im, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $w, $h);
            imagedestroy($im);
            $im = $resized;
        }
        $ext = $ext === 'jpg' ? 'jpg' : 'png';
        $name = $adId . '-' . $kind . '.' . $ext;
        $ok = $ext === 'jpg' ? imagejpeg($im, $dir . '/' . $name, 88) : imagepng($im, $dir . '/' . $name, 7);
        imagedestroy($im);
        if (!$ok) {
            throw new HttpException(500, 'Unable to save image');
        }
        return $name;
    }

    public static function imageFile(array $ad, string $kind): ?string
    {
        $name = $ad[$kind . '_path'] ?? null;
        if (!$name) {
            return null;
        }
        $file = Config::storage() . '/uploads/ads/' . basename($name);
        return is_file($file) ? $file : null;
    }

    public static function submitForReview(array $ad): void
    {
        if ($ad['status'] === 'review') {
            return;
        }
        Database::query("UPDATE ads SET status='review',updated_at=? WHERE id=?", [now(), $ad['id']]);
    }

    public static function approve(array $ad, string $adminId, string $note = ''): void
    {
        $now = now();
        $row = ['status' => 'approved', 'approved_at' => $ad['approved_at'] ?: $now, 'updated_at' => $now, 'notes' => $note ?: $ad['notes'], 'paused' => 0];
        Database::update('ads', $row, 'id=?', [$ad['id']]);
        if (!(int)$ad['is_house']) {
            AdOrders::startForAd($ad['id']);
        }
        Audit::log($adminId, 'ad.approve', 'ad', $ad['id']);
        if ($ad['user_id']) {
            $left = AdOrders::remaining($ad['id']);
            Notifier::send($ad['user_id'], 'ad', 'Advert approved: ' . $ad['title'], $left > 0 ? 'Your advert is live with ' . number_format($left) . ' impressions to deliver.' : 'Your advert is approved; buy a package to start it running.');
            $email = Database::value('SELECT email FROM users WHERE id=?', [$ad['user_id']]);
            if ($email) {
                Mailer::send((string)$email, 'Your advert is live · ME News', '<p><b>' . e($ad['title']) . '</b> has been approved' . ($left > 0 ? ' and is now running with ' . number_format($left) . ' impressions to deliver.' : '.') . '</p><p>Watch impressions and clicks in your <a href="' . e(absolute_url('/dashboard#advertising')) . '">dashboard</a>.</p>');
            }
        }
    }

    public static function reject(array $ad, string $adminId, string $note): void
    {
        Database::update('ads', ['status' => 'rejected', 'notes' => $note, 'updated_at' => now()], 'id=?', [$ad['id']]);
        Audit::log($adminId, 'ad.reject', 'ad', $ad['id'], $note);
        if ($ad['user_id']) {
            Notifier::send($ad['user_id'], 'ad', 'Advert not approved: ' . $ad['title'], $note ?: 'Please review our advertising guidelines and try again.');
        }
    }

    public static function setPaused(array $ad, bool $paused): void
    {
        Database::update('ads', ['paused' => $paused ? 1 : 0, 'updated_at' => now()], 'id=?', [$ad['id']]);
    }

    /** Admin: mark paid outside a gateway (bank transfer, comp) for N months. */
    public static function activateManual(array $ad, int $months, string $adminId, string $status = 'comped'): void
    {
        $from = max(time(), (int)strtotime((string)($ad['current_period_end'] ?: 'now')));
        $end = gmdate('Y-m-d\TH:i:s', strtotime("+{$months} month", $from)) . '+00:00';
        Database::update('ads', ['plan_status' => $status, 'gateway' => 'manual', 'current_period_end' => $end, 'status' => 'approved', 'approved_at' => $ad['approved_at'] ?: now(), 'updated_at' => now()], 'id=?', [$ad['id']]);
        Database::insert('ad_payments', ['ad_id' => $ad['id'], 'gateway' => 'manual', 'reference' => 'admin:' . $adminId, 'amount_cents' => $status === 'comped' ? 0 : self::price() * $months, 'currency' => self::currency(), 'status' => 'paid', 'created_at' => now(), 'detail' => "{$months} month(s)"]);
        Audit::log($adminId, 'ad.activate', 'ad', $ad['id'], "{$status} {$months}m");
    }

    /**
     * Apply a gateway subscription state.
     * @param array{status:string,subscription_id?:string,customer_id?:string,period_end?:string,amount_cents?:int,reference?:string} $data
     */
    public static function applySubscription(string $adId, string $gateway, array $data): void
    {
        $ad = self::find($adId);
        if (!$ad) {
            return;
        }
        $row = ['gateway' => $gateway, 'updated_at' => now()];
        if (!empty($data['subscription_id'])) {
            $row['gateway_subscription_id'] = $data['subscription_id'];
        }
        if (!empty($data['customer_id'])) {
            $row['gateway_customer_id'] = $data['customer_id'];
        }
        if (!empty($data['period_end'])) {
            $row['current_period_end'] = $data['period_end'];
        }
        $row['plan_status'] = $data['status'];
        if ($data['status'] === 'active' && empty($row['current_period_end']) && empty($ad['current_period_end'])) {
            $row['current_period_end'] = gmdate('Y-m-d\TH:i:s', strtotime('+1 month')) . '+00:00';
        }
        Database::update('ads', $row, 'id=?', [$adId]);
        if (!empty($data['reference'])) {
            if (!Database::one('SELECT id FROM ad_payments WHERE reference=?', [$data['reference']])) {
                Database::insert('ad_payments', ['ad_id' => $adId, 'gateway' => $gateway, 'reference' => $data['reference'], 'amount_cents' => (int)($data['amount_cents'] ?? 0), 'currency' => self::currency(), 'status' => 'paid', 'created_at' => now(), 'detail' => $data['detail'] ?? null]);
            }
        }
        if ($ad['user_id'] && in_array($data['status'], ['active'], true) && $ad['plan_status'] !== 'active') {
            Notifier::send($ad['user_id'], 'ad', 'Advertising subscription active', 'Thank you — "' . $ad['title'] . '" will keep running while your subscription is active.');
        }
    }

    public static function findBySubscription(string $subscriptionId): ?array
    {
        return Database::one('SELECT * FROM ads WHERE gateway_subscription_id=?', [$subscriptionId]);
    }

    /** Remaining free-trial days (0 when none) — used to line up gateway trials with ours. */
    public static function trialDaysLeft(array $ad): int
    {
        if ($ad['plan_status'] !== 'trial' || !$ad['trial_ends_at']) {
            return 0;
        }
        return max(0, (int)floor((strtotime($ad['trial_ends_at']) - time()) / 86400));
    }

    // ------------------------------------------------------------- serving

    /**
     * Pick live ads for a placement on a page, weighted, and count impressions.
     *
     * $page: home | section | story | county | notices | other. The package tier decides
     * eligibility: only 'premium' runs on the home page; banners need 'site' or 'premium';
     * sidebars accept every tier. Score = tier weight × local match × pacing × jitter, where
     * pacing nudges an advert that is behind its 30-day delivery schedule upwards and one
     * that is ahead downwards, so a 10,000-impression package is spread over its run rather
     * than burnt in a day. House ads fill whatever is left.
     */
    public static function pick(string $placement, string $county = '', string $town = '', int $limit = 1, array $exclude = [], string $page = 'other'): array
    {
        if (!Database::installed()) {
            return [];
        }
        $rows = Database::all("SELECT * FROM ads WHERE status='approved' AND paused=0 AND (placement='both' OR placement=?)", [$placement]);
        $pool = [];
        foreach ($rows as $ad) {
            if (in_array($ad['id'], $exclude, true) || !self::isLive($ad)) {
                continue;
            }
            $house = (int)$ad['is_house'] === 1;
            $tier = AdPackages::TIERS[$ad['tier'] ?? 'sidebar'] ?? AdPackages::TIERS['sidebar'];
            if (!$house) {
                if ($page === 'home' && !$tier['home']) {
                    continue;
                }
                if ($placement === 'banner' && !$tier['banner']) {
                    continue;
                }
            }
            $local = 0;
            if ($ad['target_county'] !== '' && $ad['target_county'] !== null) {
                if ($county === '' || strcasecmp($ad['target_county'], $county) !== 0) {
                    continue; // targeted elsewhere
                }
                $local = 1;
                if ($ad['target_town'] && $town !== '' && strcasecmp($ad['target_town'], $town) === 0) {
                    $local = 2;
                }
            }
            $pacing = 1.0;
            if (!$house) {
                $o = AdOrders::active($ad['id']);
                if ($o) {
                    $days = 30;
                    $elapsed = max(0, time() - (int)strtotime($o['started_at'] ?: $o['paid_at'] ?: $o['created_at'])) / 86400;
                    $expected = min(1.0, $elapsed / $days);
                    $actual = (int)$o['impressions'] > 0 ? (int)$o['impressions_used'] / (int)$o['impressions'] : 0;
                    $pacing = $actual < $expected ? 1.6 : ($actual > $expected + 0.15 ? 0.6 : 1.0);
                }
            }
            $base = $house ? 0.4 : (float)$tier['weight'] * max(1, (int)$ad['weight']);
            $ad['_score'] = $base * (1 + $local * 1.5) * $pacing * (mt_rand(60, 100) / 100);
            $pool[] = $ad;
        }
        usort($pool, static fn($a, $b) => $b['_score'] <=> $a['_score']);
        $picked = array_slice($pool, 0, $limit);
        $day = gmdate('Y-m-d');
        foreach ($picked as &$ad) {
            unset($ad['_score']);
            Database::query('UPDATE ads SET impressions=impressions+1 WHERE id=?', [$ad['id']]);
            Database::query('INSERT INTO ad_stats(ad_id,day,impressions,clicks) VALUES(?,?,1,0) ON CONFLICT(ad_id,day) DO UPDATE SET impressions=impressions+1', [$ad['id'], $day]);
            if ((int)$ad['is_house'] !== 1 && ($ad['plan_status'] ?? '') === 'credits') {
                AdOrders::consume($ad['id']);
            }
            $ad = self::present($ad);
        }
        return $picked;
    }

    public static function click(string $id): ?string
    {
        $ad = self::find($id);
        if (!$ad || !preg_match('~^https?://~i', (string)$ad['url'])) {
            return null;
        }
        Database::query('UPDATE ads SET clicks=clicks+1 WHERE id=?', [$id]);
        Database::query('INSERT INTO ad_stats(ad_id,day,impressions,clicks) VALUES(?,?,0,1) ON CONFLICT(ad_id,day) DO UPDATE SET clicks=clicks+1', [$id, gmdate('Y-m-d')]);
        return $ad['url'];
    }

    /** Daily impressions/clicks for the last N days (oldest first, gaps filled). */
    public static function stats(string $id, int $days = 30): array
    {
        $since = gmdate('Y-m-d', time() - $days * 86400);
        $rows = Database::all('SELECT day,impressions,clicks FROM ad_stats WHERE ad_id=? AND day>=? ORDER BY day', [$id, $since]);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['day']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = ['day' => $d, 'impressions' => (int)($map[$d]['impressions'] ?? 0), 'clicks' => (int)($map[$d]['clicks'] ?? 0)];
        }
        return $out;
    }

    // ------------------------------------------------------------- rendering

    /** Render an ad for a placement: 'sidebar' (card) or 'banner' (leaderboard / in-article). */
    public static function render(array $ad, string $format = 'sidebar', bool $preview = false): string
    {
        $d = is_array($ad['design'] ?? null) ? $ad['design'] : (json_decode($ad['design_json'] ?? '{}', true) ?: []);
        $d += self::TEMPLATES['aurora'];
        $d['template'] ??= 'aurora';
        $d['shape'] ??= 'orb';
        $d['align'] ??= 'left';
        $href = $preview ? '#' : '/ads/' . e($ad['id']) . '/go';
        $host = preg_replace('~^www\.~', '', (string)parse_url((string)$ad['url'], PHP_URL_HOST));
        $logoUrl = $ad['logo_url'] ?? ($ad['logo_path'] ? '/media/ad/' . $ad['id'] . '/logo' : null);
        $imageUrl = $ad['image_url'] ?? ($ad['image_path'] ? '/media/ad/' . $ad['id'] . '/image' : null);
        $logo = $logoUrl
            ? '<span class="ad__logo"><img src="' . e($logoUrl) . '" alt="" loading="lazy"></span>'
            : '<span class="ad__logo ad__logo--mono">' . e(\MeNews\Ui::initials((string)$ad['business_name'])) . '</span>';
        $style = '--bg1:' . e($d['bg1']) . ';--bg2:' . e($d['bg2']) . ';--fg:' . e($d['fg']) . ';--ac:' . e($d['ac']) . ($imageUrl && $d['template'] === 'photo' ? ';--img:url(' . e($imageUrl) . ')' : '');
        $badge = $ad['badge'] ? '<span class="ad__badge">' . e($ad['badge']) . '</span>' : '';
        return '<a class="ad ad--' . e($format) . ' ad--tpl-' . e($d['template']) . ' ad--shape-' . e($d['shape']) . ' ad--' . e($d['align']) . '" href="' . $href . '" ' . ($preview ? 'onclick="return false"' : 'target="_blank" rel="sponsored noopener"') . ' style="' . $style . '" aria-label="Advertisement: ' . e($ad['business_name']) . '">'
            . '<span class="ad__deco" aria-hidden="true"></span>'
            . '<span class="ad__tag">Sponsored</span>'
            . '<span class="ad__top">' . $logo . $badge . '</span>'
            . '<span class="ad__body"><b class="ad__head">' . e($ad['title']) . '</b>' . ($ad['body'] ? '<span class="ad__sub">' . e($ad['body']) . '</span>' : '') . '</span>'
            . '<span class="ad__foot"><span class="ad__cta">' . e($ad['cta'] ?: 'Learn more') . ' →</span><span class="ad__biz">' . e($ad['business_name']) . ($host ? ' · ' . e($host) : '') . '</span></span>'
            . '</a>';
    }
}
