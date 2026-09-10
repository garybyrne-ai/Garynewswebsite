<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Ads;
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
        return View::page('advertise', [
            'user' => Auth::user(), 'nav' => Categories::NAV,
            'title' => 'Advertise on ME News Ireland — ' . Ads::priceLabel() . ', ' . Ads::trialDays() . '-day free trial',
            'description' => 'Reach readers across Ireland with a designed ad in sidebars and banners. ' . Ads::priceLabel() . ' after a ' . Ads::trialDays() . '-day free trial.',
            'pricing' => Ads::pricing(),
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
        return Response::json(['ads' => $ads, 'pricing' => Ads::pricing()]);
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
        }
        return Response::json(['ok' => true, 'ad' => Ads::present($ad)]);
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
        Database::query('DELETE FROM ads WHERE id=?', [$ad['id']]);
        Database::query('DELETE FROM ad_stats WHERE ad_id=?', [$ad['id']]);
        Audit::log($u['id'], 'ad.delete', 'ad', $ad['id']);
        return Response::json(['ok' => true]);
    }

    public static function checkoutStripe(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        if ($ad['status'] !== 'approved') {
            throw new HttpException(409, 'Your advert needs newsroom approval before you subscribe.');
        }
        return Response::json(['url' => Stripe::adCheckoutUrl($u, $ad)]);
    }

    public static function checkoutPaypal(Request $r, array $p): Response
    {
        [$u, $ad] = self::own($r, $p);
        if ($ad['status'] !== 'approved') {
            throw new HttpException(409, 'Your advert needs newsroom approval before you subscribe.');
        }
        return Response::json(['url' => PayPal::subscribeUrl($u, $ad)]);
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
        $price = (float)str_replace(',', '.', $r->post('price', '25', 12));
        Ads::updateSettings((int)round($price * 100), (int)$r->post('trial_days', '7', 4), strtoupper($r->post('currency', 'EUR', 3)));
        Audit::log($u['id'], 'ads.settings', 'settings', 'ads', Ads::priceLabel() . ' / ' . Ads::trialDays() . 'd');
        return Response::json(['ok' => true, 'pricing' => Ads::pricing()]);
    }

    public static function adminStats(Request $r, array $p): Response
    {
        self::staff();
        $ad = Ads::find($p['id']);
        if (!$ad) {
            throw new HttpException(404, 'Advert not found');
        }
        return Response::json(['ad' => Ads::present($ad), 'stats' => Ads::stats($ad['id'], 30), 'payments' => Database::all('SELECT * FROM ad_payments WHERE ad_id=? ORDER BY created_at DESC LIMIT 50', [$ad['id']])]);
    }
}
