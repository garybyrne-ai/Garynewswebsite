<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * Razorpay — UPI (BHIM, Google Pay, PhonePe, Paytm and every other UPI app), cards, netbanking
 * and wallets for Indian buyers. Two flows, both hosted pages Razorpay serves itself so no
 * checkout JS or CSP changes are needed here — the browser is simply redirected there and back:
 *
 *  - Ad packages (one-time): a Payment Link. Razorpay's hosted page shows UPI QR/intent, cards
 *    etc.; on completion it redirects to callback_url. That redirect is never trusted on its
 *    own — the return handler re-reads the link's status from Razorpay, and the webhook is the
 *    authoritative source (same principle as the existing Stripe/PayPal code).
 *  - Wire+ membership (recurring): a Subscription, which supports UPI AutoPay mandates as well
 *    as cards. Razorpay's hosted authorisation page has no reliable return redirect, so the
 *    membership activates from the webhook alone; the button opens it in a new tab and the
 *    dashboard already polls billing status.
 *
 * .env: RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET (or entered in the
 * newsroom under Settings → Payment gateways, encrypted the same way as the other gateways).
 */
final class Razorpay
{
    private const BASE = 'https://api.razorpay.com/v1';

    public static function configured(): bool
    {
        return Secrets::get('RAZORPAY_KEY_ID') !== '' && Secrets::get('RAZORPAY_KEY_SECRET') !== '';
    }

    private static function authHeader(): string
    {
        return 'Authorization: Basic ' . base64_encode(Secrets::get('RAZORPAY_KEY_ID') . ':' . Secrets::get('RAZORPAY_KEY_SECRET'));
    }

    private static function get(string $path): array
    {
        return json_decode(Remote::get(self::BASE . $path, [self::authHeader()], 30), true) ?: [];
    }

    private static function post(string $path, array $body): array
    {
        return Remote::json(self::BASE . $path, $body, [self::authHeader()], 'json', 30);
    }

    // ------------------------------------------------------------ ad packages (one-time)

    /** Create a Payment Link for an ad package order and return its hosted URL. */
    public static function orderCheckoutUrl(array $user, array $order): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'UPI/Razorpay is not configured yet');
        }
        if (strcasecmp((string)$order['currency'], 'INR') !== 0) {
            throw new HttpException(503, 'UPI is only available for rupee pricing');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || (Config::production() && !str_starts_with($base, 'https://'))) {
            throw new HttpException(503, 'Configure the public HTTPS URL');
        }
        $res = self::post('/payment_links', [
            'amount' => (int)$order['price_cents'],
            'currency' => 'INR',
            'accept_partial' => false,
            'description' => mb_substr(Config::appName() . ' advertising: ' . $order['package_name'] . ' (' . number_format((int)$order['impressions']) . ' impressions)', 0, 255),
            'customer' => ['name' => $user['display_name'] ?? '', 'email' => $user['email']],
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'reference_id' => $order['id'],
            'notes' => ['kind' => 'ad_order', 'order_id' => $order['id']],
            'callback_url' => $base . '/billing/ads/return?order=' . $order['id'] . '&gateway=razorpay',
            'callback_method' => 'get',
        ]);
        $id = (string)($res['id'] ?? '');
        $url = (string)($res['short_url'] ?? '');
        if ($id === '' || $url === '') {
            throw new HttpException(502, 'Razorpay did not create a payment link');
        }
        AdOrders::setGatewayRef($order['id'], $id);
        return $url;
    }

    /** Return from Razorpay: never trust the query string, re-read the link status server-side. */
    public static function capturePaymentLink(string $orderId, array $user): array
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=? AND user_id=?', [$orderId, $user['id']]);
        if (!$o) {
            throw new HttpException(404, 'Order not found');
        }
        if ($o['status'] !== 'pending') {
            return AdOrders::present($o);
        }
        if (!$o['gateway_ref']) {
            throw new HttpException(400, 'No Razorpay payment link to check');
        }
        $link = self::get('/payment_links/' . rawurlencode((string)$o['gateway_ref']));
        if (($link['status'] ?? '') === 'paid') {
            $paymentId = (string)$o['gateway_ref'];
            foreach ($link['payments'] ?? [] as $p) {
                if (($p['status'] ?? '') === 'captured') {
                    $paymentId = (string)$p['payment_id'];
                    break;
                }
            }
            $amount = (int)($link['amount_paid'] ?? $link['amount'] ?? $o['price_cents']);
            return AdOrders::markPaid($orderId, 'razorpay', $paymentId, $amount, 'INR') ?? AdOrders::present($o);
        }
        return AdOrders::present($o);
    }

    // ------------------------------------------------------------ Wire+ membership (recurring)

    /** A plan for the current Wire+ price/interval, created once and cached. */
    private static function planId(string $interval): string
    {
        $cents = $interval === 'year' ? Membership::annualCents() : Membership::priceCents();
        $key = 'razorpay_plan_' . $interval . '_' . $cents;
        $plan = Database::setting($key, '');
        if ($plan) {
            return $plan;
        }
        $res = self::post('/plans', [
            'period' => $interval === 'year' ? 'yearly' : 'monthly',
            'interval' => 1,
            'item' => [
                'name' => 'Wire+ membership (' . ($interval === 'year' ? 'annual' : 'monthly') . ')',
                'amount' => $cents, 'currency' => 'INR',
                'description' => 'Ad-free reading, alerts for up to ten areas, the 7am email and the archive',
            ],
        ]);
        $plan = (string)($res['id'] ?? '');
        if ($plan === '') {
            throw new HttpException(502, 'Razorpay plan could not be created');
        }
        Database::setSetting($key, $plan);
        return $plan;
    }

    /** Create a Wire+ subscription (UPI AutoPay, cards…) and return the hosted authorisation URL. */
    public static function subscriptionUrl(array $user, string $interval = 'month'): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'UPI/Razorpay is not configured yet');
        }
        if ($user['plan'] === 'Wire+') {
            throw new HttpException(409, 'Wire+ is already active');
        }
        $res = self::post('/subscriptions', [
            'plan_id' => self::planId($interval),
            'total_count' => $interval === 'year' ? 10 : 120,
            'customer_notify' => 1,
            'notes' => ['kind' => 'wireplus', 'user_id' => $user['id'], 'interval' => $interval],
        ]);
        $url = (string)($res['short_url'] ?? '');
        if ($url === '') {
            throw new HttpException(502, 'Razorpay did not return an authorisation link');
        }
        return $url;
    }

    // ------------------------------------------------------------ webhook

    public static function webhook(string $payload, string $signature, string $eventId): array
    {
        $secret = Secrets::get('RAZORPAY_WEBHOOK_SECRET');
        if ($secret === '') {
            throw new HttpException(503, 'Razorpay webhook not configured');
        }
        if ($signature === '' || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw new HttpException(400, 'Invalid Razorpay webhook signature');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['event'])) {
            throw new HttpException(400, 'Invalid webhook');
        }
        $dedupeKey = $eventId !== '' ? $eventId : hash('sha256', $payload);
        if (Database::one('SELECT id FROM razorpay_events WHERE id=?', [$dedupeKey])) {
            return ['received' => true];
        }
        $type = (string)$event['event'];
        Database::transaction(static function () use ($type, $event, $dedupeKey): void {
            Database::insert('razorpay_events', ['id' => $dedupeKey, 'created_at' => now()]);
            $payload = $event['payload'] ?? [];
            if ($type === 'payment_link.paid') {
                $link = $payload['payment_link']['entity'] ?? [];
                $orderId = (string)($link['reference_id'] ?? '');
                if ($orderId !== '' && Database::one('SELECT id FROM ad_orders WHERE id=?', [$orderId])) {
                    $paymentId = (string)($payload['payment']['entity']['id'] ?? $link['id'] ?? '');
                    $amount = (int)($link['amount_paid'] ?? $link['amount'] ?? 0);
                    AdOrders::markPaid($orderId, 'razorpay', $paymentId, $amount, 'INR');
                }
                return;
            }
            if (str_starts_with($type, 'subscription.')) {
                $sub = $payload['subscription']['entity'] ?? [];
                $notes = $sub['notes'] ?? [];
                if (($notes['kind'] ?? '') !== 'wireplus' || empty($notes['user_id'])) {
                    return;
                }
                $uid = (string)$notes['user_id'];
                if (!Database::one('SELECT id FROM users WHERE id=?', [$uid])) {
                    return;
                }
                $subId = (string)($sub['id'] ?? '');
                if (in_array($type, ['subscription.activated', 'subscription.charged'], true)) {
                    Database::query('UPDATE subscriptions SET gateway=?,razorpay_subscription_id=?,plan=?,status=?,updated_at=? WHERE user_id=?', ['razorpay', $subId, 'Wire+', 'active', now(), $uid]);
                    Database::query("UPDATE users SET plan='Wire+' WHERE id=?", [$uid]);
                    if ($type === 'subscription.activated') {
                        Notifier::send($uid, 'billing', 'Wire+ activated', 'Your membership is active.');
                    }
                } elseif (in_array($type, ['subscription.cancelled', 'subscription.halted', 'subscription.completed'], true)) {
                    Database::query("UPDATE subscriptions SET plan='free',status=? WHERE razorpay_subscription_id=?", [$type === 'subscription.halted' ? 'past_due' : 'cancelled', $subId]);
                    if ($type !== 'subscription.halted') {
                        Database::query("UPDATE users SET plan='free' WHERE id=? AND plan='Wire+'", [$uid]);
                    }
                }
            }
        });
        return ['received' => true];
    }
}
