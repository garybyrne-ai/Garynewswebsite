<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Breaking-news web push. Standard Web Push (RFC 8030/8291/8292) via the browser's own
 * PushManager — no app store, no third-party notification service. VAPID keys are generated
 * once and stored in settings; minishlink/web-push does the actual RFC 8291 payload encryption
 * (aes128gcm + ECDH + HKDF), which is exactly the kind of crypto not worth hand-rolling.
 *
 * Deliberately editor-triggered, not automatic on every wire story: a push for every headline
 * is how readers turn notifications off forever. An editor decides a story is push-worthy from
 * the newsroom; the push then goes to subscribers of that story's state, plus everyone
 * subscribed nationally (no state chosen).
 */
final class Push
{
    public static function available(): bool
    {
        return class_exists(WebPush::class);
    }

    public static function configured(): bool
    {
        return self::available() && self::vapidKeys() !== [];
    }

    public static function publicKey(): string
    {
        return self::vapidKeys()['publicKey'] ?? '';
    }

    /** Get-or-generate the VAPID keypair (once per install). */
    private static function vapidKeys(): array
    {
        if (!self::available()) {
            return [];
        }
        $public = (string)Database::setting('push_vapid_public', '');
        $private = (string)Database::setting('push_vapid_private', '');
        if ($public !== '' && $private !== '') {
            return ['publicKey' => $public, 'privateKey' => $private];
        }
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        Database::setSetting('push_vapid_public', $keys['publicKey']);
        Database::setSetting('push_vapid_private', $keys['privateKey']);
        return $keys;
    }

    private static function subject(): string
    {
        $contact = (string)Database::setting('contact_email', Config::get('MAIL_REPLY_TO', Mailer::from()));
        return filter_var($contact, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $contact : (Config::baseUrl() ?: 'mailto:news@bharatwire.in');
    }

    private static function client(): WebPush
    {
        $keys = self::vapidKeys();
        // Suppressed: without ext-gmp/ext-bcmath (neither required, both merely faster) the
        // library raises an E_USER_NOTICE recommending them, which this app's error handler
        // otherwise turns into a thrown ErrorException — that would be treating a performance
        // tip as a fatal error, so it is deliberately swallowed here and nowhere else.
        return @new WebPush(['VAPID' => ['subject' => self::subject(), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]]);
    }

    /** Register or refresh a browser's push subscription. $county is optional (state alerts). */
    public static function subscribe(array $sub, ?string $userId, ?string $county, string $userAgent): void
    {
        $endpoint = (string)($sub['endpoint'] ?? '');
        $p256dh = (string)($sub['keys']['p256dh'] ?? '');
        $auth = (string)($sub['keys']['auth'] ?? '');
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new HttpException(400, 'Invalid push subscription');
        }
        $existing = Database::one('SELECT id FROM push_subscriptions WHERE endpoint=?', [$endpoint]);
        if ($existing) {
            Database::update('push_subscriptions', ['user_id' => $userId, 'county' => $county, 'p256dh' => $p256dh, 'auth' => $auth, 'user_agent' => mb_substr($userAgent, 0, 300)], 'id=?', [$existing['id']]);
            return;
        }
        Database::insert('push_subscriptions', [
            'id' => uuid(), 'user_id' => $userId, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
            'county' => $county, 'user_agent' => mb_substr($userAgent, 0, 300), 'created_at' => now(),
        ]);
    }

    public static function unsubscribe(string $endpoint): void
    {
        Database::query('DELETE FROM push_subscriptions WHERE endpoint=?', [$endpoint]);
    }

    public static function subscriberCount(): int
    {
        return Database::count('SELECT COUNT(*) FROM push_subscriptions');
    }

    /** Push a published story to subscribers of its state (plus national subscribers). Idempotent per story. */
    public static function sendToStory(array $story): array
    {
        if (!empty($story['pushed_at'])) {
            throw new HttpException(409, 'Already pushed for this story');
        }
        $url = absolute_url('/story/' . $story['slug']);
        $result = self::send($story['title'], excerpt((string)($story['summary'] ?: ''), 140), $url, $story['county'] ?: null);
        Database::query('UPDATE stories SET pushed_at=? WHERE id=?', [now(), $story['id']]);
        return $result;
    }

    /**
     * Send a push to everyone subscribed to a state (plus everyone with no state chosen —
     * national subscribers), or to every subscriber when $county is null.
     * @return array{sent:int,failed:int,expired:int}
     */
    public static function send(string $title, string $body, string $url, ?string $county = null): array
    {
        if (!self::configured()) {
            throw new HttpException(503, 'Push notifications are not configured yet');
        }
        $rows = $county
            ? Database::all('SELECT * FROM push_subscriptions WHERE county=? OR county IS NULL OR county=\'\'', [$county])
            : Database::all('SELECT * FROM push_subscriptions');
        if (!$rows) {
            return ['sent' => 0, 'failed' => 0, 'expired' => 0];
        }
        $payload = json_encode([
            'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 200),
            'url' => $url, 'icon' => absolute_url('/assets/img/logo-512.png'), 'badge' => absolute_url('/assets/img/favicon.svg'),
        ], JSON_UNESCAPED_UNICODE);
        $webPush = self::client();
        foreach ($rows as $row) {
            try {
                $sub = Subscription::create(['endpoint' => $row['endpoint'], 'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']]]);
                $webPush->queueNotification($sub, $payload);
            } catch (Throwable $e) {
                error_log('Push queue (' . $row['id'] . '): ' . $e->getMessage());
            }
        }
        $sentEndpoints = [];
        $failed = 0;
        $expired = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sentEndpoints[] = $report->getEndpoint();
            } elseif ($report->isSubscriptionExpired()) {
                $expired[] = $report->getEndpoint();
            } else {
                $failed++;
                error_log('Push send failed for ' . $report->getEndpoint() . ': ' . $report->getReason());
            }
        }
        if ($expired) {
            $placeholders = implode(',', array_fill(0, count($expired), '?'));
            Database::query("DELETE FROM push_subscriptions WHERE endpoint IN ({$placeholders})", $expired);
        }
        if ($sentEndpoints) {
            $placeholders = implode(',', array_fill(0, count($sentEndpoints), '?'));
            Database::query("UPDATE push_subscriptions SET last_sent_at=? WHERE endpoint IN ({$placeholders})", [now(), ...$sentEndpoints]);
        }
        return ['sent' => count($sentEndpoints), 'failed' => $failed, 'expired' => count($expired)];
    }
}
