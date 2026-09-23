<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/** ME+ membership billing through Stripe Checkout and signed webhooks. */
final class Stripe
{
    /** ME+ needs only the secret key: prices come from the newsroom settings (inline price_data). */
    public static function configured(): bool
    {
        return Secrets::get('STRIPE_SECRET_KEY') !== '';
    }

    /** Stripe usable for advertising (only the secret key is needed; prices are inline). */
    public static function adsConfigured(): bool
    {
        return Secrets::get('STRIPE_SECRET_KEY') !== '';
    }

    public static function checkoutUrl(array $user, string $interval = 'month'): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'Stripe is not configured yet');
        }
        if ($user['plan'] === 'ME+') {
            throw new HttpException(409, 'ME+ is already active');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || (Config::production() && !str_starts_with($base, 'https://'))) {
            throw new HttpException(503, 'Configure the public HTTPS URL');
        }
        $annual = $interval === 'year';
        $lineItem = Secrets::get('STRIPE_PRICE_ME_PLUS') !== '' && !$annual
            ? ['price' => Secrets::get('STRIPE_PRICE_ME_PLUS'), 'quantity' => 1]
            : ['quantity' => 1, 'price_data' => [
                'currency' => 'eur', 'unit_amount' => $annual ? Membership::annualCents() : Membership::priceCents(),
                'recurring' => ['interval' => $annual ? 'year' : 'month'],
                'product_data' => ['name' => 'ME+ membership (' . ($annual ? 'annual' : 'monthly') . ')', 'description' => 'Ad-free reading, alerts for up to ten areas, the 7am email and the archive'],
            ]];
        $session = Remote::json('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription',
            'line_items' => [$lineItem],
            'success_url' => $base . '/dashboard?billing=success',
            'cancel_url' => $base . '/dashboard?billing=cancelled',
            'customer_email' => $user['email'],
            'metadata' => ['user_id' => $user['id']],
            'subscription_data' => ['metadata' => ['user_id' => $user['id']]],
        ], ['Authorization: Bearer ' . Secrets::get('STRIPE_SECRET_KEY')], 'form');
        return (string)$session['url'];
    }

    /** Stripe Checkout for an advertising subscription (price from settings, trial aligned with ours). */
    public static function adCheckoutUrl(array $user, array $ad): string
    {
        if (Secrets::get('STRIPE_SECRET_KEY') === '') {
            throw new HttpException(503, 'Stripe is not configured yet');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || (Config::production() && !str_starts_with($base, 'https://'))) {
            throw new HttpException(503, 'Configure the public HTTPS URL');
        }
        $body = [
            'mode' => 'subscription',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower(Ads::currency()), 'unit_amount' => Ads::price(), 'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => Config::appName() . ' advertising — ' . $ad['business_name'], 'description' => 'Sidebar and banner placements, local targeting, monthly'],
                ],
            ]],
            'success_url' => $base . '/dashboard?ad=success&session_id={CHECKOUT_SESSION_ID}#advertising',
            'cancel_url' => $base . '/dashboard?ad=cancelled#advertising',
            'customer_email' => $user['email'],
            'client_reference_id' => $ad['id'],
            'metadata' => ['kind' => 'ad', 'ad_id' => $ad['id'], 'user_id' => $user['id']],
            'subscription_data' => ['metadata' => ['kind' => 'ad', 'ad_id' => $ad['id'], 'user_id' => $user['id']]],
        ];
        $left = Ads::trialDaysLeft($ad);
        if ($left >= 1) {
            $body['subscription_data']['trial_period_days'] = $left;
        }
        $session = Remote::json('https://api.stripe.com/v1/checkout/sessions', $body, ['Authorization: Bearer ' . Secrets::get('STRIPE_SECRET_KEY')], 'form');
        return (string)$session['url'];
    }

    /** One-time Stripe Checkout for an ad package order. */
    public static function orderCheckoutUrl(array $user, array $order): string
    {
        if (Secrets::get('STRIPE_SECRET_KEY') === '') {
            throw new HttpException(503, 'Card payments are not configured yet');
        }
        $base = rtrim(Config::baseUrl(), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || (Config::production() && !str_starts_with($base, 'https://'))) {
            throw new HttpException(503, 'Configure the public HTTPS URL');
        }
        $session = Remote::json('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order['currency']), 'unit_amount' => (int)$order['price_cents'],
                    'product_data' => ['name' => Config::appName() . ' advertising — ' . $order['package_name'], 'description' => number_format((int)$order['impressions']) . ' impressions · ' . (AdPackages::TIERS[$order['tier']]['blurb'] ?? '')],
                ],
            ]],
            'success_url' => $base . '/billing/ads/return?order=' . $order['id'] . '&gateway=stripe&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base . '/advertise?cancelled=1',
            'customer_email' => $user['email'],
            'client_reference_id' => $order['id'],
            'metadata' => ['kind' => 'ad_order', 'order_id' => $order['id'], 'user_id' => $user['id']],
            'payment_intent_data' => ['metadata' => ['kind' => 'ad_order', 'order_id' => $order['id']]],
        ], ['Authorization: Bearer ' . Secrets::get('STRIPE_SECRET_KEY'), 'Idempotency-Key: order-' . $order['id']], 'form');
        AdOrders::setGatewayRef($order['id'], (string)$session['id']);
        return (string)$session['url'];
    }

    public static function webhook(string $payload, string $signature): array
    {
        $secret = Secrets::get('STRIPE_WEBHOOK_SECRET');
        if ($secret === '') {
            throw new HttpException(503, 'Stripe webhook not configured');
        }
        preg_match('/(?:^|,)t=(\d+)/', $signature, $m);
        $time = (int)($m[1] ?? 0);
        preg_match_all('/(?:^|,)v1=([a-f0-9]+)/', $signature, $sigs);
        $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
        $valid = false;
        foreach ($sigs[1] as $sig) {
            $valid = $valid || hash_equals($expected, $sig);
        }
        if (!$valid || abs(time() - $time) > 300) {
            throw new HttpException(400, 'Invalid Stripe webhook signature');
        }
        $event = json_decode($payload, true);
        if (!isset($event['id'], $event['type'], $event['data']['object'])) {
            throw new HttpException(400, 'Invalid webhook');
        }
        if (Database::one('SELECT id FROM stripe_events WHERE id=?', [$event['id']])) {
            return ['received' => true];
        }
        $obj = $event['data']['object'];
        Database::transaction(static function () use ($event, $obj): void {
            Database::insert('stripe_events', ['id' => $event['id'], 'created_at' => now()]);
            // ---- Advertising package orders (one-time payments)
            if (($obj['metadata']['kind'] ?? '') === 'ad_order' && !empty($obj['metadata']['order_id'])) {
                if ($event['type'] === 'checkout.session.completed' && ($obj['payment_status'] ?? '') === 'paid') {
                    AdOrders::markPaid((string)$obj['metadata']['order_id'], 'stripe', (string)($obj['payment_intent'] ?? $obj['id']), (int)($obj['amount_total'] ?? 0), (string)($obj['currency'] ?? ''));
                } elseif ($event['type'] === 'payment_intent.succeeded') {
                    AdOrders::markPaid((string)$obj['metadata']['order_id'], 'stripe', (string)$obj['id'], (int)($obj['amount_received'] ?? 0), (string)($obj['currency'] ?? ''));
                } elseif ($event['type'] === 'charge.refunded') {
                    Database::query("UPDATE ad_orders SET status='refunded',note='Refunded via Stripe',completed_at=? WHERE id=? AND status<>'refunded'", [now(), (string)$obj['metadata']['order_id']]);
                }
                return;
            }
            // ---- Advertising subscriptions (metadata.kind = ad)
            if (($obj['metadata']['kind'] ?? '') === 'ad' && !empty($obj['metadata']['ad_id'])) {
                $adId = (string)$obj['metadata']['ad_id'];
                if ($event['type'] === 'checkout.session.completed') {
                    Ads::applySubscription($adId, 'stripe', ['status' => 'active', 'subscription_id' => $obj['subscription'] ?? null, 'customer_id' => $obj['customer'] ?? null]);
                } elseif (in_array($event['type'], ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
                    $status = $event['type'] === 'customer.subscription.deleted' ? 'cancelled' : (string)($obj['status'] ?? 'incomplete');
                    $map = ['active' => 'active', 'trialing' => 'active', 'past_due' => 'past_due', 'canceled' => 'cancelled', 'cancelled' => 'cancelled', 'unpaid' => 'past_due', 'incomplete' => 'past_due', 'incomplete_expired' => 'cancelled', 'paused' => 'cancelled'];
                    $end = !empty($obj['current_period_end']) ? gmdate('Y-m-d\TH:i:s', (int)$obj['current_period_end']) . '+00:00' : null;
                    Ads::applySubscription($adId, 'stripe', ['status' => $map[$status] ?? 'past_due', 'subscription_id' => $obj['id'] ?? null, 'customer_id' => $obj['customer'] ?? null] + ($end ? ['period_end' => $end] : []));
                }
                return;
            }
            if ($event['type'] === 'invoice.paid' && !empty($obj['subscription'])) {
                $ad = Ads::findBySubscription((string)$obj['subscription']);
                if ($ad) {
                    $line = $obj['lines']['data'][0] ?? [];
                    $end = !empty($line['period']['end']) ? gmdate('Y-m-d\TH:i:s', (int)$line['period']['end']) . '+00:00' : null;
                    Ads::applySubscription($ad['id'], 'stripe', ['status' => 'active', 'reference' => 'stripe:' . ($obj['id'] ?? uuid()), 'amount_cents' => (int)($obj['amount_paid'] ?? 0), 'detail' => 'Stripe invoice'] + ($end ? ['period_end' => $end] : []));
                }
                return;
            }
            if ($event['type'] === 'invoice.payment_failed' && !empty($obj['subscription'])) {
                $ad = Ads::findBySubscription((string)$obj['subscription']);
                if ($ad) {
                    Ads::applySubscription($ad['id'], 'stripe', ['status' => 'past_due']);
                }
                return;
            }
            // ---- ME+ membership
            if ($event['type'] === 'checkout.session.completed' && ($obj['mode'] ?? '') === 'subscription') {
                $uid = (string)($obj['metadata']['user_id'] ?? '');
                if (in_array($obj['payment_status'] ?? '', ['paid', 'no_payment_required'], true) && Database::one('SELECT id FROM users WHERE id=?', [$uid])) {
                    Database::query('UPDATE subscriptions SET stripe_customer_id=?,stripe_subscription_id=?,plan=?,status=?,updated_at=? WHERE user_id=?', [$obj['customer'] ?? null, $obj['subscription'] ?? null, 'ME+', 'active', now(), $uid]);
                    Database::query("UPDATE users SET plan='ME+' WHERE id=?", [$uid]);
                    Notifier::send($uid, 'billing', 'ME+ activated', 'Your membership is active.');
                }
            } elseif (in_array($event['type'], ['customer.subscription.updated', 'customer.subscription.deleted'], true)) {
                $sub = Database::one('SELECT * FROM subscriptions WHERE stripe_subscription_id=?', [$obj['id']]);
                if ($sub) {
                    $status = $event['type'] === 'customer.subscription.deleted' ? 'canceled' : (string)($obj['status'] ?? 'incomplete');
                    $plan = in_array($status, ['active', 'trialing'], true) ? 'ME+' : 'free';
                    Database::query('UPDATE users SET plan=? WHERE id=?', [$plan, $sub['user_id']]);
                    Database::query('UPDATE subscriptions SET plan=?,status=?,updated_at=? WHERE id=?', [$plan, $status, now(), $sub['id']]);
                }
            }
        });
        return ['received' => true];
    }
}
