<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * Orders: one package purchase = one bundle of impressions. Payment is confirmed only by a
 * signed webhook or a server-side lookup of the gateway session, never by the return URL.
 * A paid order unlocks the designer; approval starts serving; the order completes when the
 * impressions are used up.
 */
final class AdOrders
{
    public static function find(string $id): ?array
    {
        $row = Database::one('SELECT * FROM ad_orders WHERE id=?', [$id]);
        return $row ? self::present($row) : null;
    }

    public static function forUser(string $userId): array
    {
        return array_map([self::class, 'present'], Database::all('SELECT * FROM ad_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 100', [$userId]));
    }

    public static function all(string $status = ''): array
    {
        $sql = 'SELECT o.*, u.email AS owner_email, u.display_name AS owner_name, a.business_name FROM ad_orders o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN ads a ON a.id=o.ad_id';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE o.status=?';
            $args[] = $status;
        }
        return array_map([self::class, 'present'], Database::all($sql . ' ORDER BY o.created_at DESC LIMIT 500', $args));
    }

    public static function present(array $o): array
    {
        $o['remaining'] = max(0, (int)$o['impressions'] - (int)$o['impressions_used']);
        $o['progress'] = (int)$o['impressions'] > 0 ? (int)round((int)$o['impressions_used'] / (int)$o['impressions'] * 100) : 0;
        $o['price_label'] = AdPackages::money((int)$o['price_cents']);
        $o['tier_label'] = AdPackages::TIERS[$o['tier']]['label'] ?? $o['tier'];
        $o['usable'] = in_array($o['status'], ['paid', 'running'], true) && $o['remaining'] > 0;
        $o['state_label'] = match ($o['status']) {
            'pending' => 'Awaiting payment',
            'paid' => $o['ad_id'] ? 'Paid · advert awaiting approval' : 'Paid · design your advert',
            'running' => 'Running · ' . number_format($o['remaining']) . ' impressions left',
            'completed' => 'Completed · all impressions delivered',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled',
            default => ucfirst((string)$o['status']),
        };
        return $o;
    }

    /** Paid orders of the user that are not yet attached to an advert. */
    public static function credits(string $userId): array
    {
        return array_map([self::class, 'present'], Database::all("SELECT * FROM ad_orders WHERE user_id=? AND status='paid' AND ad_id IS NULL ORDER BY created_at", [$userId]));
    }

    /** Start a pending order for a package. Returns the row. */
    public static function create(array $user, array $package, string $gateway, ?string $adId = null): array
    {
        if (!in_array($gateway, ['stripe', 'paypal'], true)) {
            throw new HttpException(400, 'Choose a payment method');
        }
        if ($adId !== null) {
            $ad = Ads::find($adId);
            if (!$ad || $ad['user_id'] !== $user['id'] || (int)$ad['is_house'] === 1) {
                throw new HttpException(404, 'Advert not found');
            }
        }
        RateLimiter::hit($user['id'] . '|order', 20, 3600, 'Too many checkout attempts. Try again later.');
        $id = uuid();
        Database::insert('ad_orders', [
            'id' => $id, 'user_id' => $user['id'], 'package_id' => $package['id'], 'package_name' => $package['name'], 'tier' => $package['tier'],
            'ad_id' => $adId, 'impressions' => (int)$package['impressions'], 'price_cents' => (int)$package['price_cents'], 'currency' => Ads::currency(),
            'gateway' => $gateway, 'status' => 'pending', 'created_at' => now(),
        ]);
        // Abandoned checkouts are cleared after a day so the list stays honest.
        Database::query("DELETE FROM ad_orders WHERE status='pending' AND created_at<?", [gmdate('Y-m-d\TH:i:s', time() - 86400) . '+00:00']);
        return self::find($id);
    }

    public static function setGatewayRef(string $id, string $ref): void
    {
        Database::query('UPDATE ad_orders SET gateway_ref=? WHERE id=?', [$ref, $id]);
    }

    /**
     * Mark an order paid (idempotent). Records the payment, attaches to the advert when the
     * order was a top-up, and tells the buyer. Amount is verified against the order.
     */
    public static function markPaid(string $id, string $gateway, string $paymentRef, int $amountCents, string $currency): ?array
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=?', [$id]);
        if (!$o) {
            return null;
        }
        if ($o['status'] !== 'pending') {
            return self::present($o);
        }
        if ($amountCents < (int)$o['price_cents'] || strcasecmp($currency, $o['currency']) !== 0) {
            error_log("Ad order {$id}: paid {$amountCents} {$currency}, expected {$o['price_cents']} {$o['currency']}");
            Database::query("UPDATE ad_orders SET note=? WHERE id=?", ['Amount mismatch: ' . $amountCents . ' ' . $currency, $id]);
            return self::present($o);
        }
        Database::query("UPDATE ad_orders SET status='paid',gateway=?,payment_ref=?,paid_at=? WHERE id=? AND status='pending'", [$gateway, $paymentRef, now(), $id]);
        if (!Database::one('SELECT id FROM ad_payments WHERE reference=?', [$gateway . ':' . $paymentRef])) {
            Database::insert('ad_payments', ['ad_id' => $o['ad_id'] ?? '', 'gateway' => $gateway, 'reference' => $gateway . ':' . $paymentRef, 'amount_cents' => $amountCents, 'currency' => strtoupper($currency), 'status' => 'paid', 'created_at' => now(), 'detail' => $o['package_name'] . ' · order ' . $id]);
        }
        if ($o['ad_id']) {
            self::attach($id, $o['ad_id']);
        }
        Notifier::send($o['user_id'], 'ad', 'Payment received: ' . $o['package_name'], $o['ad_id'] ? 'Your advert has ' . number_format((int)$o['impressions']) . ' more impressions.' : 'Design your advert from the Advertising tab and submit it for review.');
        $email = Database::value('SELECT email FROM users WHERE id=?', [$o['user_id']]);
        if ($email) {
            Mailer::send((string)$email, 'Payment received · ' . $o['package_name'], '<p>Thanks — we received ' . e(AdPackages::money($amountCents)) . ' for <b>' . e($o['package_name']) . '</b> (' . number_format((int)$o['impressions']) . ' impressions).</p><p>' . ($o['ad_id'] ? 'The impressions have been added to your advert.' : '<a href="' . e(absolute_url('/dashboard#advertising')) . '">Design your advert</a> and submit it for review; it goes live once an editor approves it.') . '</p><p style="color:#59685f;font-size:12px">Order ' . e($id) . ' · reference ' . e($paymentRef) . '</p>');
        }
        return self::find($id);
    }

    /** Attach a paid order to an advert (designer flow or top-up). Serving starts at approval. */
    public static function attach(string $orderId, string $adId): void
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=?', [$orderId]);
        $ad = Ads::find($adId);
        if (!$o || !$ad || $o['user_id'] !== $ad['user_id']) {
            throw new HttpException(403, 'That order does not belong to this advert');
        }
        if (!in_array($o['status'], ['paid', 'running'], true)) {
            throw new HttpException(409, 'That order is not usable');
        }
        Database::query('UPDATE ad_orders SET ad_id=? WHERE id=?', [$adId, $orderId]);
        // The advert takes the highest tier of any usable order attached to it.
        $tier = self::bestTier($adId);
        Database::query("UPDATE ads SET tier=?,plan_status='credits',updated_at=? WHERE id=?", [$tier, now(), $adId]);
        if ($ad['status'] === 'approved') {
            Database::query("UPDATE ad_orders SET status='running',started_at=COALESCE(started_at,?) WHERE id=? AND status='paid'", [now(), $orderId]);
            Database::query('UPDATE ads SET order_id=COALESCE(order_id,?),paused=0 WHERE id=?', [$orderId, $adId]);
        }
    }

    public static function bestTier(string $adId): string
    {
        $rank = ['sidebar' => 1, 'site' => 2, 'premium' => 3];
        $best = 'sidebar';
        foreach (Database::all("SELECT tier FROM ad_orders WHERE ad_id=? AND status IN ('paid','running') AND impressions_used<impressions", [$adId]) as $r) {
            if (($rank[$r['tier']] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $r['tier'];
            }
        }
        return $best;
    }

    /** When an advert is approved, its paid orders start running. */
    public static function startForAd(string $adId): void
    {
        Database::query("UPDATE ad_orders SET status='running',started_at=COALESCE(started_at,?) WHERE ad_id=? AND status='paid'", [now(), $adId]);
        $first = Database::value("SELECT id FROM ad_orders WHERE ad_id=? AND status='running' ORDER BY created_at LIMIT 1", [$adId]);
        Database::query('UPDATE ads SET order_id=?,tier=?,updated_at=? WHERE id=?', [$first ?: null, self::bestTier($adId), now(), $adId]);
    }

    /** The order currently being consumed by an advert (oldest running one with impressions left). */
    public static function active(string $adId): ?array
    {
        $row = Database::one("SELECT * FROM ad_orders WHERE ad_id=? AND status='running' AND impressions_used<impressions ORDER BY created_at LIMIT 1", [$adId]);
        return $row ? self::present($row) : null;
    }

    /** Remaining impressions across all running orders of an advert. */
    public static function remaining(string $adId): int
    {
        return (int)Database::value("SELECT COALESCE(SUM(impressions-impressions_used),0) FROM ad_orders WHERE ad_id=? AND status='running'", [$adId]);
    }

    /** Count one impression against the advert's active order; completes the order when spent. */
    public static function consume(string $adId): void
    {
        $o = Database::one("SELECT id,impressions,impressions_used,user_id,package_name FROM ad_orders WHERE ad_id=? AND status='running' AND impressions_used<impressions ORDER BY created_at LIMIT 1", [$adId]);
        if (!$o) {
            return;
        }
        Database::query('UPDATE ad_orders SET impressions_used=impressions_used+1 WHERE id=?', [$o['id']]);
        if ((int)$o['impressions_used'] + 1 >= (int)$o['impressions']) {
            Database::query("UPDATE ad_orders SET status='completed',completed_at=? WHERE id=?", [now(), $o['id']]);
            $next = Database::value("SELECT id FROM ad_orders WHERE ad_id=? AND status='running' AND impressions_used<impressions ORDER BY created_at LIMIT 1", [$adId]);
            Database::query('UPDATE ads SET order_id=?,tier=?,updated_at=? WHERE id=?', [$next ?: null, self::bestTier($adId), now(), $adId]);
            if (!$next) {
                $ad = Ads::find($adId);
                Notifier::send($o['user_id'], 'ad', 'Your advert has used all its impressions', 'Buy another package from the Advertising tab to keep "' . ($ad['title'] ?? 'your advert') . '" running.');
                $email = Database::value('SELECT email FROM users WHERE id=?', [$o['user_id']]);
                if ($email) {
                    Mailer::send((string)$email, 'Your advert has finished its run', '<p><b>' . e($ad['title'] ?? 'Your advert') . '</b> has delivered all ' . number_format((int)$o['impressions']) . ' impressions of your ' . e($o['package_name']) . ' package.</p><p><a href="' . e(absolute_url('/advertise')) . '">Buy more impressions</a> to keep it running; your design and approval carry over.</p>');
                }
            }
        }
    }

    /** Admin: grant impressions without a gateway (bank transfer, comp). */
    public static function grant(array $user, array $package, string $adminId, ?string $adId, string $note): array
    {
        $id = uuid();
        Database::insert('ad_orders', [
            'id' => $id, 'user_id' => $user['id'], 'package_id' => $package['id'], 'package_name' => $package['name'], 'tier' => $package['tier'],
            'ad_id' => null, 'impressions' => (int)$package['impressions'], 'price_cents' => (int)$package['price_cents'], 'currency' => Ads::currency(),
            'gateway' => 'manual', 'payment_ref' => 'admin:' . $adminId, 'status' => 'paid', 'created_at' => now(), 'paid_at' => now(), 'note' => $note,
        ]);
        if ($adId) {
            self::attach($id, $adId);
        }
        Audit::log($adminId, 'ad.order.grant', 'ad_order', $id, $package['name'] . ' → ' . $user['email']);
        return self::find($id);
    }

    /** When an advert is deleted, unused impressions go back to the member as a credit. */
    public static function release(string $adId): void
    {
        Database::query("UPDATE ad_orders SET status='paid', ad_id=NULL, started_at=NULL WHERE ad_id=? AND status IN ('paid','running') AND impressions_used<impressions", [$adId]);
        Database::query("UPDATE ad_orders SET status='completed', completed_at=? WHERE ad_id=? AND status='running'", [now(), $adId]);
    }

    /** Remove a pending order whose checkout never opened. */
    public static function discard(string $id): void
    {
        Database::query("DELETE FROM ad_orders WHERE id=? AND status='pending'", [$id]);
    }
    public static function refund(string $id, string $adminId, string $note): array
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=?', [$id]);
        if (!$o) {
            throw new HttpException(404, 'Order not found');
        }
        Database::query("UPDATE ad_orders SET status='refunded',note=?,completed_at=? WHERE id=?", [$note, now(), $id]);
        if ($o['ad_id']) {
            $next = Database::value("SELECT id FROM ad_orders WHERE ad_id=? AND status='running' AND impressions_used<impressions ORDER BY created_at LIMIT 1", [$o['ad_id']]);
            Database::query('UPDATE ads SET order_id=?,tier=?,updated_at=? WHERE id=?', [$next ?: null, self::bestTier($o['ad_id']), now(), $o['ad_id']]);
        }
        Audit::log($adminId, 'ad.order.refund', 'ad_order', $id, $note);
        return self::find($id);
    }

    /** Return URL after Stripe: look the session up server-side; the query string proves nothing. */
    public static function confirmStripe(string $orderId, string $sessionId, array $user): array
    {
        $o = Database::one('SELECT * FROM ad_orders WHERE id=? AND user_id=?', [$orderId, $user['id']]);
        if (!$o) {
            throw new HttpException(404, 'Order not found');
        }
        if ($o['status'] !== 'pending') {
            return self::present($o);
        }
        if ($o['gateway_ref'] !== $sessionId) {
            throw new HttpException(400, 'Session does not match this order');
        }
        $session = Remote::json('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId), null, ['Authorization: Bearer ' . Config::get('STRIPE_SECRET_KEY')], 'json', 30);
        if (($session['payment_status'] ?? '') === 'paid' && (string)($session['metadata']['order_id'] ?? '') === $orderId) {
            return self::markPaid($orderId, 'stripe', (string)($session['payment_intent'] ?? $sessionId), (int)($session['amount_total'] ?? 0), (string)($session['currency'] ?? '')) ?? self::present($o);
        }
        return self::present($o);
    }
}
