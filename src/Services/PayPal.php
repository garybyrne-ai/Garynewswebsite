<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * PayPal Subscriptions for ME Ads (REST API v1/billing). A product and a plan per price are
 * created on demand and cached in settings, so changing the price in the newsroom simply
 * creates a new plan next time someone subscribes.
 *
 * .env: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_MODE=sandbox|live, PAYPAL_WEBHOOK_ID
 */
final class PayPal
{
    public static function configured(): bool
    {
        return Secrets::get('PAYPAL_CLIENT_ID') !== '' && Secrets::get('PAYPAL_CLIENT_SECRET') !== '';
    }

    public static function base(): string
    {
        return Secrets::get('PAYPAL_MODE', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function token(): string
    {
        $cached = json_decode((string)Database::setting('paypal_token', ''), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return (string)$cached['token'];
        }
        $res = Remote::json(self::base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials'], [
            'Authorization: Basic ' . base64_encode(Secrets::get('PAYPAL_CLIENT_ID') . ':' . Secrets::get('PAYPAL_CLIENT_SECRET')),
        ], 'form', 30);
        if (empty($res['access_token'])) {
            throw new HttpException(502, 'PayPal did not issue a token');
        }
        Database::setSetting('paypal_token', json_encode(['token' => $res['access_token'], 'expires' => time() + (int)($res['expires_in'] ?? 3600)]));
        return (string)$res['access_token'];
    }

    private static function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $headers = array_merge(['Authorization: Bearer ' . self::token(), 'Prefer: return=representation'], $headers);
        if ($method === 'GET') {
            $raw = Remote::get(self::base() . $path, $headers, 30);
            return json_decode($raw, true) ?: [];
        }
        return Remote::json(self::base() . $path, $body ?? [], $headers, 'json', 30);
    }

    /** Product + plan for the current price (cached per price/currency). */
    private static function planId(): string
    {
        $cents = Ads::price();
        $currency = Ads::currency();
        $key = 'paypal_plan_' . $currency . '_' . $cents;
        $plan = Database::setting($key, '');
        if ($plan) {
            return $plan;
        }
        $product = Database::setting('paypal_product_id', '');
        if (!$product) {
            $p = self::call('POST', '/v1/catalogs/products', ['name' => Config::appName() . ' advertising', 'description' => 'Local advertising on ' . Config::appName(), 'type' => 'SERVICE', 'category' => 'ADVERTISING_AND_MARKETING'], ['PayPal-Request-Id: product-' . uuid()]);
            $product = (string)($p['id'] ?? '');
            if ($product === '') {
                throw new HttpException(502, 'PayPal product could not be created');
            }
            Database::setSetting('paypal_product_id', $product);
        }
        $res = self::call('POST', '/v1/billing/plans', [
            'product_id' => $product,
            'name' => Config::appName() . ' ad — ' . Ads::priceLabel($cents),
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => number_format($cents / 100, 2, '.', ''), 'currency_code' => $currency]],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 2],
        ], ['PayPal-Request-Id: plan-' . uuid()]);
        $plan = (string)($res['id'] ?? '');
        if ($plan === '') {
            throw new HttpException(502, 'PayPal plan could not be created');
        }
        Database::setSetting($key, $plan);
        return $plan;
    }

    /** Create a subscription and return the PayPal approval URL. Billing starts when our free trial ends. */
    public static function subscribeUrl(array $user, array $ad): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'PayPal is not configured yet');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            throw new HttpException(503, 'Set the site address in Settings first');
        }
        $body = [
            'plan_id' => self::planId(),
            'custom_id' => $ad['id'],
            'subscriber' => ['email_address' => $user['email']],
            'application_context' => [
                'brand_name' => Config::appName(), 'user_action' => 'SUBSCRIBE_NOW', 'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $base . '/billing/paypal/return?ad=' . $ad['id'], 'cancel_url' => $base . '/dashboard?ad=cancelled#advertising',
            ],
        ];
        $left = Ads::trialDaysLeft($ad);
        if ($left >= 1) {
            $body['start_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$ad['trial_ends_at']));
        }
        $res = self::call('POST', '/v1/billing/subscriptions', $body, ['PayPal-Request-Id: sub-' . $ad['id'] . '-' . time()]);
        foreach ($res['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                Database::update('ads', ['gateway' => 'paypal', 'gateway_subscription_id' => $res['id'] ?? null, 'updated_at' => now()], 'id=?', [$ad['id']]);
                return (string)$link['href'];
            }
        }
        throw new HttpException(502, 'PayPal did not return an approval link');
    }

    /** One-time PayPal order for an ad package: create, return the approval link. */
    public static function orderUrl(array $user, array $order): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'PayPal is not configured yet');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            throw new HttpException(503, 'Set the site address in Settings first');
        }
        $res = self::call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order['id'], 'custom_id' => $order['id'],
                'description' => mb_substr(Config::appName() . ' advertising: ' . $order['package_name'] . ' (' . number_format((int)$order['impressions']) . ' impressions)', 0, 127),
                'amount' => ['currency_code' => $order['currency'], 'value' => number_format((int)$order['price_cents'] / 100, 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => Config::appName(), 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $base . '/billing/ads/return?order=' . $order['id'] . '&gateway=paypal', 'cancel_url' => $base . '/advertise?cancelled=1',
            ]]],
        ], ['PayPal-Request-Id: order-' . $order['id']]);
        $id = (string)($res['id'] ?? '');
        if ($id === '') {
            throw new HttpException(502, 'PayPal did not create the order');
        }
        AdOrders::setGatewayRef($order['id'], $id);
        foreach ($res['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return (string)$link['href'];
            }
        }
        throw new HttpException(502, 'PayPal did not return an approval link');
    }

    /** Capture after the buyer returns; the capture response is the proof of payment. */
    public static function captureOrder(string $orderId, array $user): array
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=? AND user_id=?', [$orderId, $user['id']]);
        if (!$o) {
            throw new HttpException(404, 'Order not found');
        }
        if ($o['status'] !== 'pending') {
            return AdOrders::present($o);
        }
        if (!$o['gateway_ref']) {
            throw new HttpException(400, 'No PayPal order to capture');
        }
        $res = self::call('POST', '/v2/checkout/orders/' . rawurlencode((string)$o['gateway_ref']) . '/capture', [], ['PayPal-Request-Id: capture-' . $orderId]);
        $capture = $res['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($res['status'] ?? '') === 'COMPLETED' && $capture && ($capture['status'] ?? '') === 'COMPLETED') {
            $amount = (int)round((float)($capture['amount']['value'] ?? 0) * 100);
            return AdOrders::markPaid($orderId, 'paypal', (string)$capture['id'], $amount, (string)($capture['amount']['currency_code'] ?? '')) ?? AdOrders::present($o);
        }
        return AdOrders::present($o);
    }

    /** After the buyer returns: read the subscription and activate the ad if PayPal says so. */
    public static function confirm(string $subscriptionId, string $adId): array
    {
        $sub = self::call('GET', '/v1/billing/subscriptions/' . rawurlencode($subscriptionId));
        $status = strtoupper((string)($sub['status'] ?? ''));
        $ad = Ads::find($adId);
        if (!$ad || ($sub['custom_id'] ?? $adId) !== $adId) {
            throw new HttpException(404, 'Advert not found for this subscription');
        }
        if (in_array($status, ['ACTIVE', 'APPROVED'], true)) {
            $next = $sub['billing_info']['next_billing_time'] ?? null;
            $data = ['status' => 'active', 'subscription_id' => $subscriptionId, 'customer_id' => $sub['subscriber']['payer_id'] ?? null];
            if ($next) {
                $data['period_end'] = gmdate('Y-m-d\TH:i:s', strtotime($next)) . '+00:00';
            } elseif ($ad['trial_ends_at'] && Ads::trialDaysLeft($ad) >= 1) {
                $data['period_end'] = gmdate('Y-m-d\TH:i:s', strtotime('+1 month', strtotime($ad['trial_ends_at']))) . '+00:00';
            }
            Ads::applySubscription($adId, 'paypal', $data);
        }
        return ['status' => $status];
    }

    /** Webhook: verify the signature with PayPal, then mirror subscription state. */
    public static function webhook(string $payload, array $headers): array
    {
        $webhookId = Secrets::get('PAYPAL_WEBHOOK_ID');
        if ($webhookId === '') {
            throw new HttpException(503, 'PAYPAL_WEBHOOK_ID is not configured');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['event_type'])) {
            throw new HttpException(400, 'Invalid webhook');
        }
        $verify = self::call('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['PAYPAL-AUTH-ALGO'] ?? '', 'cert_url' => $headers['PAYPAL-CERT-URL'] ?? '',
            'transmission_id' => $headers['PAYPAL-TRANSMISSION-ID'] ?? '', 'transmission_sig' => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '', 'webhook_id' => $webhookId, 'webhook_event' => $event,
        ]);
        if (($verify['verification_status'] ?? '') !== 'SUCCESS') {
            throw new HttpException(400, 'PayPal signature verification failed');
        }
        $res = $event['resource'] ?? [];
        $type = (string)$event['event_type'];
        // One-time package orders
        if ($type === 'PAYMENT.CAPTURE.COMPLETED' && !empty($res['custom_id']) && Database::one('SELECT id FROM ad_orders WHERE id=?', [(string)$res['custom_id']])) {
            AdOrders::markPaid((string)$res['custom_id'], 'paypal', (string)($res['id'] ?? uuid()), (int)round((float)($res['amount']['value'] ?? 0) * 100), (string)($res['amount']['currency_code'] ?? ''));
            return ['received' => true];
        }
        if ($type === 'PAYMENT.CAPTURE.REFUNDED' && !empty($res['custom_id'])) {
            Database::query("UPDATE ad_orders SET status='refunded',note='Refunded via PayPal',completed_at=? WHERE id=? AND status<>'refunded'", [now(), (string)$res['custom_id']]);
            return ['received' => true];
        }
        $subId = (string)($res['billing_agreement_id'] ?? $res['id'] ?? '');
        $ad = $subId ? Ads::findBySubscription($subId) : null;
        if (!$ad && !empty($res['custom_id'])) {
            $ad = Ads::find((string)$res['custom_id']);
        }
        if ($ad) {
            switch ($type) {
                case 'BILLING.SUBSCRIPTION.ACTIVATED':
                case 'BILLING.SUBSCRIPTION.RE-ACTIVATED':
                    $next = $res['billing_info']['next_billing_time'] ?? null;
                    Ads::applySubscription($ad['id'], 'paypal', ['status' => 'active', 'subscription_id' => $subId] + ($next ? ['period_end' => gmdate('Y-m-d\TH:i:s', strtotime($next)) . '+00:00'] : []));
                    break;
                case 'PAYMENT.SALE.COMPLETED':
                    $amount = (int)round((float)($res['amount']['total'] ?? 0) * 100);
                    Ads::applySubscription($ad['id'], 'paypal', ['status' => 'active', 'period_end' => gmdate('Y-m-d\TH:i:s', strtotime('+1 month')) . '+00:00', 'reference' => 'paypal:' . ($res['id'] ?? uuid()), 'amount_cents' => $amount, 'detail' => 'PayPal payment']);
                    break;
                case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                    Ads::applySubscription($ad['id'], 'paypal', ['status' => 'past_due']);
                    break;
                case 'BILLING.SUBSCRIPTION.CANCELLED':
                case 'BILLING.SUBSCRIPTION.SUSPENDED':
                case 'BILLING.SUBSCRIPTION.EXPIRED':
                    Ads::applySubscription($ad['id'], 'paypal', ['status' => 'cancelled']);
                    break;
            }
        }
        return ['received' => true];
    }
}
