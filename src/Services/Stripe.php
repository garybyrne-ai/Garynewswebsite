<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/** ME+ membership billing through Stripe Checkout and signed webhooks. */
final class Stripe
{
    public static function configured(): bool
    {
        return Config::get('STRIPE_SECRET_KEY') !== '' && Config::get('STRIPE_PRICE_ME_PLUS') !== '';
    }

    public static function checkoutUrl(array $user): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'Stripe is not configured yet');
        }
        if ($user['plan'] === 'ME+') {
            throw new HttpException(409, 'ME+ is already active');
        }
        $base = rtrim(Config::get('PUBLIC_BASE_URL'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || (Config::production() && !str_starts_with($base, 'https://'))) {
            throw new HttpException(503, 'Configure the public HTTPS URL');
        }
        $session = Remote::json('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription',
            'line_items' => [['price' => Config::get('STRIPE_PRICE_ME_PLUS'), 'quantity' => 1]],
            'success_url' => $base . '/dashboard?billing=success',
            'cancel_url' => $base . '/dashboard?billing=cancelled',
            'customer_email' => $user['email'],
            'metadata' => ['user_id' => $user['id']],
            'subscription_data' => ['metadata' => ['user_id' => $user['id']]],
        ], ['Authorization: Bearer ' . Config::get('STRIPE_SECRET_KEY')], 'form');
        return (string)$session['url'];
    }

    public static function webhook(string $payload, string $signature): array
    {
        $secret = Config::get('STRIPE_WEBHOOK_SECRET');
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
