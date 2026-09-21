<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\AdOrders;
use MeNews\Services\AdPackages;
use MeNews\Services\Ads;
use MeNews\Services\RateLimiter;
use MeNews\Services\Audit;
use MeNews\Services\PayPal;
use MeNews\Services\Stripe;
use MeNews\Support\Categories;
use MeNews\Support\Locations;
use MeNews\View;

/** ME Ads: advertiser self-service, billing hand-offs, newsroom management and serving. */
final class AdsController
{
    // ------------------------------------------------------------ public

    public static function advertisePage(Request $r): Response
    {
        $demo = [
            'id' => 'demo', 'business_name' => 'Your business', 'title' => 'Your headline, seen across Ireland', 'body' => 'Sidebar cards and banners on the home page, section pages and inside every article.',
            'cta' => 'Start free trial', 'badge' => '7-day trial', 'url' => 'https://example.ie', 'logo_path' => null, 'image_path' => null,
            'design' => ['template' => 'aurora'] + Ads::TEMPLATES['aurora'] + ['shape' => 'orb', 'align' => 'left'],
        ];
        $packages = AdPackages::all();
        $cheapest = $packages ? $packages[0]['price_label'] : '';
        $demo['cta'] = 'Buy impressions';
        $demo['badge'] = $cheapest ? 'From ' . $cheapest : '';
        return View::page('advertise', [
            'user' => Auth::user(), 'nav' => Categories::NAV,
            'title' => 'Advertise on ME News Ireland — impression packages from ' . $cheapest,
            'description' => 'Buy a package of impressions, design your ad in minutes, and run it in sidebars and banners across ME News Ireland. From ' . $cheapest . '.',
            'pricing' => Ads::pricing(),
            'packages' => $packages,
            'tiers' => AdPackages::TIERS,
            'credits' => Auth::user() ? AdOrders::credits(Auth::user()['id']) : [],
            'cancelled' => $r->query('cancelled') === '1',
            'topup' => self::topupTarget($r),
            'demo' => $demo,
            'liveCount' => Database::installed() ? count(array_filter(Database::all("SELECT * FROM ads WHERE status='approved'"), [Ads::class, 'isLive'])) : 0,
            'bodyClass' => 'page-advertise',
        ]);
    }

    public static function pricing(Request $r): Response
    {
        return Response::json(Ads::pricing());
    }

    public static function go(Request $r, array $p): Response
    {
        $url = Ads::click($p['id']);
        if (!$url) {
            throw new HttpException(404, 'Advert not found');
        }
        return Response::redirect($url);
    }

    public static function media(Request $r, array $p): Response
    {
        $ad = Ads::find($p['id']);
        $file = $ad ? Ads::imageFile($ad, $p['kind']) : null;
        if (!$file) {
            throw new HttpException(404, 'Image not found');
        }
        header('Content-Type: ' . mime_content_type($file));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }

    /** Live preview for the designer (authenticated: any member). */
    /** Browser beacon: an advert in a rotating slot was actually shown. One count per advert per viewer per minute. */
    public static function impression(Request $r): Response
    {
        $ids = array_slice(array_unique(array_filter(preg_split('/[\s,]+/', $r->post('ids', '', 800)) ?: [], static fn($v) => preg_match('/^[a-f0-9]{8,40}$/', $v) === 1)), 0, 12);
        $counted = 0;
        foreach ($ids as $id) {
            try {
                RateLimiter::hit($r->ip() . '|imp|' . $id, 1, 60, 'seen');
            } catch (HttpException) {
                continue;
            }
            $ad = Database::one("SELECT * FROM ads WHERE id=? AND status='approved' AND paused=0", [$id]);
            if ($ad && Ads::isLive($ad)) {
                Ads::countImpression($ad);
                $counted++;
            }
        }
        return Response::json(['ok' => true, 'counted' => $counted]);
    }

    public static function preview(Request $r): Response
    {
        Auth::require();
        $get = static fn(string $k, int $max, string $d = '') => mb_substr(trim($r->post($k, $d, $max + 10)), 0, $max) ?: $d;
        $ad = [
            'id' => 'preview', 'business_name' => $get('business_name', Ads::LIMITS['business_name'], 'Your business'),
            'title' => $get('title', Ads::LIMITS['headline'], 'Your headline goes here'), 'body' => $get('body', Ads::LIMITS['subline']),
            'cta' => $get('cta', Ads::LIMITS['cta'], 'Learn more'), 'badge' => $get('badge', Ads::LIMITS['badge']), 'url' => $get('url', 300, 'https://example.ie'),
            'logo_path' => null, 'image_path' => null, 'design' => Ads::sanitizeDesign($_POST),
        ];
        if ($id = $r->post('id', '', 40)) {
            $existing = Ads::find($id);
            if ($existing) {
                $ad['logo_path'] = $existing['logo_path'];
                $ad['image_path'] = $existing['image_path'];
                $ad['logo_url'] = $existing['logo_path'] ? '/media/ad/' . $id . '/logo?t=' . time() : null;
                $ad['image_url'] = $existing['image_path'] ? '/media/ad/' . $id . '/image?t=' . time() : null;
            }
        }
        return Response::json(['sidebar' => Ads::render($ad, 'sidebar', true), 'banner' => Ads::render($ad, 'banner', true)]);
    }

    // ------------------------------------------------------------ advertiser

    public static function mine(Request $r): Response
    {
        $u = Auth::require();
        $ads = Ads::forUser($u['id']);
        foreach ($ads as &$ad) {
            $ad['stats'] = Ads::stats($ad['id'], 14);
            $ad['preview'] = Ads::render($ad, 'sidebar', true);
        }
        return Response::json(['ads' => $ads, 'pricing' => Ads::pricing(), 'credits' => AdOrders::credits($u['id']), 'orders' => AdOrders::forUser($u['id'])]);
    }

    /** Start a checkout for a package (optionally topping up an existing advert). */
    public static function checkout(Request $r, array $p): Response
    {
        $u = Auth::require();
        $package = AdPackages::find($p['id']);
        if (!$package || !$package['active']) {
            throw new HttpException(404, 'Package not found');
        }
        $gateway = $r->post('gateway', 'stripe', 10);
        $adId = $r->post('ad_id', '', 40) ?: null;
        $order = AdOrders::create($u, $package, $gateway, $adId);
        try {
            $url = $gateway === 'paypal' ? PayPal::orderUrl($u, $order) : Stripe::orderCheckoutUrl($u, $order);
        } catch (\Throwable $e) {
            // No checkout session was opened, so do not leave a dangling pending order behind.
            AdOrders::discard($order['id']);
            throw $e;
        }
        Audit::log($u['id'], 'ad.order.start', 'ad_order', $order['id'], $package['name'] . ' via ' . $gateway);
        return Response::json(['url' => $url, 'order' => $order['id']]);
    }

    /** Return from the gateway: confirm server-side, then send the buyer to the designer. */
    public static function orderReturn(Request $r): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::redirect('/?auth=signin&next=' . rawurlencode('/dashboard#advertising'));
        }
        $orderId = $r->query('order', '', 40);
        $gateway = $r->query('gateway', '', 10);
        try {
            $order = $gateway === 'paypal'
                ? PayPal::captureOrder($orderId, $u)
                : AdOrders::confirmStripe($orderId, $r->query('session_id', '', 120), $u);
            $state = in_array($order['status'], ['paid', 'running'], true) ? 'paid' : 'pending';
        } catch (\Throwable $e) {
            error_log('Ad order return: ' . $e->getMessage());
            $state = 'pending';
        }
        return Response::redirect('/dashboard?order=' . $state . '#advertising');
    }

    public static function save(Request $r): Response
    {
        $u = Auth::require();
        $id = $r->post('id', '', 40) ?: null;
        $ad = Ads::save($id, $_POST, $_FILES, $u);
        Audit::log($u['id'], $id ? 'ad.update' : 'ad.create', 'ad', $ad['id'], $ad['business_name']);
        if ($r->post('submit') === '1') {
            Ads::submitForReview($ad);
            $ad = Ads::find($ad['id']);
            Audit::log($u['id'], 'ad.submit', 'ad', $ad['id']);
            \MeNews\Services\Notifier::staff('ad-review', 'Advert awaiting review: ' . $ad['business_name']);
        }
        return Response::json(['ok' => true, 'ad' => Ads::present($ad)]);
    }

    /** The member's own advert named by ?ad=, so a purchase tops it up instead of starting a new one. */
    private static function topupTarget(Request $r): ?array
    {
        $u = Auth::user();
        $id = $r->query('ad', '', 40);
        if (!$u || $id === '' || !preg_match('/^[a-f0-9]+$/', $id)) {
            return null;
        }
        $ad = Ads::find($id);
        return $ad && $ad['user_id'] === $u['id'] && (int)$ad['is_house'] === 0 ? $ad : null;
    }
    private static function own(Request $r, array $p): array
    {
        $u = Auth::require();
        $ad = Ads::find($p['id']);
        if (!$ad || $ad['user_id'] !== $u['id']) {
            throw new HttpException(404, 'Advert not found');
        }
        return [$u, $ad];
    }

    /** Attach a paid, unused package to one of the member's own adverts (top-up). */
    public static function attach(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        $orderId = $r->post('order_id', '', 40);
        $order = AdOrders::find($orderId);
        if (!$order || $order['user_id'] !== $u['id'] || $order['status'] !== 'paid' || $order['ad_id']) {
            throw new HttpException(404, 'That package is not available to attach');
        }
        AdOrders::attach($orderId, $ad['id']);
        Audit::log($u['id'], 'ad.order.attach', 'ad_order', $orderId, $ad['business_name']);
        return Response::json(['ok' => true, 'ad' => Ads::present(Ads::find($ad['id']))]);
    }
    public static function submit(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        Ads::submitForReview($ad);
        Audit::log($u['id'], 'ad.submit', 'ad', $ad['id']);
        return Response::json(['ok' => true, 'ad' => Ads::present(Ads::find($ad['id']))]);
    }

    public static function pause(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        Ads::setPaused($ad, $r->post('paused') === '1');
        return Response::json(['ok' => true, 'ad' => Ads::present(Ads::find($ad['id']))]);
    }

    public static function delete(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        if (in_array($ad['plan_status'], ['active', 'past_due'], true)) {
            throw new HttpException(409, 'Cancel the subscription with your payment provider before deleting a paid advert.');
        }
        AdOrders::release($ad['id']);
        Database::query('DELETE FROM ads WHERE id=?', [$ad['id']]);
        Database::query('DELETE FROM ad_stats WHERE ad_id=?', [$ad['id']]);
        Audit::log($u['id'], 'ad.delete', 'ad', $ad['id']);
        return Response::json(['ok' => true]);
    }

    /** Legacy subscription checkout: advertising is sold as impression packages now. */
    public static function checkoutStripe(Request $r, array $p): Response
    {
        self::own($r, $p);
        throw new HttpException(410, 'Advertising is sold as impression packages now. Choose a package on the Advertise page.');
    }

    public static function checkoutPaypal(Request $r, array $p): Response
    {
        return self::checkoutStripe($r, $p);
    }

    public static function paypalReturn(Request $r): Response
    {
        $adId = $r->query('ad', '', 40);
        $subId = $r->query('subscription_id', '', 80);
        try {
            if ($adId && $subId) {
                PayPal::confirm($subId, $adId);
            }
            return Response::redirect('/dashboard?ad=paypal-success#advertising');
        } catch (\Throwable $e) {
            error_log('PayPal return: ' . $e->getMessage());
            return Response::redirect('/dashboard?ad=paypal-pending#advertising');
        }
    }

    public static function paypalWebhook(Request $r): Response
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_PAYPAL_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
        return Response::json(PayPal::webhook($r->body(), $headers));
    }

    // ------------------------------------------------------------ newsroom

    private static function staff(): array
    {
        return Auth::require(['editor', 'admin']);
    }

    public static function adminList(Request $r): Response
    {
        self::staff();
        $ads = Ads::all($r->query('status', '', 20));
        foreach ($ads as &$ad) {
            $ad['preview'] = Ads::render($ad, 'sidebar', true);
        }
        return Response::json(['ads' => $ads, 'pricing' => Ads::pricing(), 'counties' => Locations::countyNames()]);
    }

    public static function adminSave(Request $r): Response
    {
        $u = self::staff();
        $id = $r->post('id', '', 40) ?: null;
        $ad = Ads::save($id, $_POST, $_FILES, $u, true);
        if (!$id) {
            Database::query('UPDATE ads SET user_id=? WHERE id=?', [$u['id'], $ad['id']]);
        }
        Audit::log($u['id'], $id ? 'ad.admin-update' : 'ad.house-create', 'ad', $ad['id'], $ad['business_name']);
        return Response::json(['ok' => true, 'ad' => Ads::present(Ads::find($ad['id']))]);
    }

    public static function adminDecision(Request $r, array $p): Response
    {
        $u = self::staff();
        $ad = Ads::find($p['id']);
        if (!$ad) {
            throw new HttpException(404, 'Advert not found');
        }
        $d = $r->post('decision', '', 20);
        $note = $r->post('note', '', 500);
        switch ($d) {
            case 'approve':
                Ads::approve($ad, $u['id'], $note);
                break;
            case 'reject':
                Ads::reject($ad, $u['id'], $note);
                break;
            case 'pause':
                Ads::setPaused($ad, true);
                Audit::log($u['id'], 'ad.pause', 'ad', $ad['id']);
                break;
            case 'resume':
                Ads::setPaused($ad, false);
                Audit::log($u['id'], 'ad.resume', 'ad', $ad['id']);
                break;
            case 'activate':
                Auth::require(['admin']);
                $months = max(1, min(24, (int)$r->post('months', '1')));
                Ads::activateManual($ad, $months, $u['id'], $r->post('paid') === '1' ? 'active' : 'comped');
                break;
            case 'house':
                Auth::require(['admin']);
                Database::update('ads', ['is_house' => $r->post('is_house') === '1' ? 1 : 0, 'status' => 'approved', 'approved_at' => $ad['approved_at'] ?: now(), 'updated_at' => now()], 'id=?', [$ad['id']]);
                Audit::log($u['id'], 'ad.house', 'ad', $ad['id'], $r->post('is_house'));
                break;
            case 'weight':
                Database::update('ads', ['weight' => max(1, min(10, (int)$r->post('weight', '1')))], 'id=?', [$ad['id']]);
                break;
            case 'delete':
                Auth::require(['admin']);
                AdOrders::release($ad['id']);
                Database::query('DELETE FROM ads WHERE id=?', [$ad['id']]);
                Database::query('DELETE FROM ad_stats WHERE ad_id=?', [$ad['id']]);
                Audit::log($u['id'], 'ad.delete', 'ad', $ad['id']);
                return Response::json(['ok' => true, 'deleted' => true]);
            default:
                throw new HttpException(400, 'Invalid decision');
        }
        return Response::json(['ok' => true, 'ad' => Ads::present(Ads::find($ad['id']))]);
    }

    public static function adminSettings(Request $r): Response
    {
        $u = Auth::require(['admin']);
        $price = (float)str_replace(',', '.', $r->post('price', (string)(Ads::price() / 100), 12));
        Ads::updateSettings((int)round($price * 100), (int)$r->post('trial_days', (string)Ads::trialDays(), 4), strtoupper($r->post('currency', 'EUR', 3)));
        Audit::log($u['id'], 'ads.settings', 'settings', 'ads', Ads::currency());
        return Response::json(['ok' => true, 'pricing' => Ads::pricing()]);
    }

    public static function adminStats(Request $r, array $p): Response
    {
        $u = self::staff();
        $ad = Ads::find($p['id']);
        if (!$ad) {
            throw new HttpException(404, 'Advert not found');
        }
        // Money is administrators' business; editors see delivery only.
        $payments = $u['role'] === 'admin' ? Database::all('SELECT * FROM ad_payments WHERE ad_id=? ORDER BY created_at DESC LIMIT 50', [$ad['id']]) : [];
        return Response::json(['ad' => Ads::present($ad), 'stats' => Ads::stats($ad['id'], 30), 'payments' => $payments]);
    }

    // ------------------------------------------------------------ packages & orders (admin)

    public static function packages(Request $r): Response
    {
        return Response::json(['packages' => AdPackages::all(), 'tiers' => AdPackages::TIERS, 'currency' => Ads::currency(), 'stripe' => Stripe::adsConfigured(), 'paypal' => PayPal::configured()]);
    }

    public static function adminPackages(Request $r): Response
    {
        Auth::require(['admin']);
        return Response::json(['packages' => AdPackages::all(false), 'tiers' => AdPackages::TIERS, 'currency' => Ads::currency()]);
    }

    public static function adminPackageSave(Request $r): Response
    {
        $u = Auth::require(['admin']);
        $id = $r->post('id', '', 40) ?: null;
        $pkg = AdPackages::save($id, $_POST);
        Audit::log($u['id'], $id ? 'ad.package.update' : 'ad.package.create', 'ad_package', $pkg['id'], $pkg['name'] . ' ' . $pkg['price_label'] . ' / ' . $pkg['impressions']);
        return Response::json(['ok' => true, 'package' => $pkg]);
    }

    public static function adminPackageDelete(Request $r, array $p): Response
    {
        $u = Auth::require(['admin']);
        AdPackages::delete($p['id']);
        Audit::log($u['id'], 'ad.package.delete', 'ad_package', $p['id']);
        return Response::json(['ok' => true]);
    }

    public static function adminOrders(Request $r): Response
    {
        Auth::require(['admin']);
        return Response::json(['orders' => AdOrders::all($r->query('status', '', 20))]);
    }

    /** Admin: grant a package (bank transfer / comp) or refund an order. */
    public static function adminOrderAction(Request $r): Response
    {
        $u = Auth::require(['admin']);
        $action = $r->post('action', '', 20);
        if ($action === 'grant') {
            $email = mb_strtolower($r->post('email', '', 254));
            $buyer = Database::one('SELECT * FROM users WHERE email=?', [$email]);
            $pkg = AdPackages::find($r->post('package_id', '', 40));
            if (!$buyer || !$pkg) {
                throw new HttpException(400, 'Choose an existing member email and a package');
            }
            $adId = $r->post('ad_id', '', 40) ?: null;
            if ($adId && (Ads::find($adId)['user_id'] ?? null) !== $buyer['id']) {
                throw new HttpException(400, 'That advert does not belong to the member');
            }
            return Response::json(['ok' => true, 'order' => AdOrders::grant($buyer, $pkg, $u['id'], $adId, $r->post('note', '', 300))]);
        }
        if ($action === 'refund') {
            return Response::json(['ok' => true, 'order' => AdOrders::refund($r->post('order_id', '', 40), $u['id'], $r->post('note', '', 300))]);
        }
        throw new HttpException(400, 'Invalid action');
    }
}
