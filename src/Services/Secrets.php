<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * Payment gateway credentials entered from the newsroom, encrypted at rest.
 *
 * Values live in the settings table as libsodium secretbox ciphertext. The encryption key is
 * APP_KEY when one is set in .env; otherwise a random key generated once into
 * storage/data/.secret_key (outside the web root, git-ignored, mode 0600). Anything still set in
 * .env keeps working as a fallback, so nothing breaks for installs that prefer the file.
 */
final class Secrets
{
    /** name => [label, group, hint, pattern, required?] */
    public const FIELDS = [
        'STRIPE_SECRET_KEY' => ['label' => 'Stripe secret key', 'group' => 'stripe', 'hint' => 'sk_live_… or sk_test_… from Developers → API keys', 'pattern' => '/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/'],
        'STRIPE_WEBHOOK_SECRET' => ['label' => 'Stripe webhook signing secret', 'group' => 'stripe', 'hint' => 'whsec_… shown when you add the webhook endpoint', 'pattern' => '/^whsec_[A-Za-z0-9]{10,}$/'],
        'STRIPE_PRICE_WIRE_PLUS' => ['label' => 'Stripe price ID for monthly Wire+ (optional)', 'group' => 'stripe', 'hint' => 'price_… — leave blank to bill the price from Settings', 'pattern' => '/^price_[A-Za-z0-9]{6,}$/'],
        'PAYPAL_CLIENT_ID' => ['label' => 'PayPal client ID', 'group' => 'paypal', 'hint' => 'From the PayPal developer dashboard → your REST app', 'pattern' => '/^[A-Za-z0-9_\-]{20,}$/'],
        'PAYPAL_CLIENT_SECRET' => ['label' => 'PayPal client secret', 'group' => 'paypal', 'hint' => 'Same app, “Secret key”', 'pattern' => '/^[A-Za-z0-9_\-]{20,}$/'],
        'PAYPAL_WEBHOOK_ID' => ['label' => 'PayPal webhook ID', 'group' => 'paypal', 'hint' => 'Shown after you add the webhook URL to the app', 'pattern' => '/^[A-Z0-9]{10,}$/'],
        'PAYPAL_MODE' => ['label' => 'PayPal mode', 'group' => 'paypal', 'hint' => 'sandbox while testing, live when ready', 'pattern' => '/^(sandbox|live)$/'],
        'RAZORPAY_KEY_ID' => ['label' => 'Razorpay key ID', 'group' => 'razorpay', 'hint' => 'rzp_live_… or rzp_test_… from Settings → API Keys', 'pattern' => '/^rzp_(live|test)_[A-Za-z0-9]{10,}$/'],
        'RAZORPAY_KEY_SECRET' => ['label' => 'Razorpay key secret', 'group' => 'razorpay', 'hint' => 'Shown once when the key ID is generated', 'pattern' => '/^[A-Za-z0-9]{10,}$/'],
        'RAZORPAY_WEBHOOK_SECRET' => ['label' => 'Razorpay webhook secret', 'group' => 'razorpay', 'hint' => 'The secret you set when adding the webhook URL in the Razorpay dashboard', 'pattern' => '/^\S[\s\S]{4,255}$/'],
        'SMTP_PASS' => ['label' => 'SMTP password', 'group' => 'mail', 'hint' => 'The password or API key your mail host gave you', 'pattern' => '/^\S[\s\S]{2,255}$/'],
        'BREVO_API_KEY' => ['label' => 'Brevo API key', 'group' => 'mail', 'hint' => 'xkeysib-… from Brevo → SMTP & API → API keys', 'pattern' => '/^xkeysib-[A-Za-z0-9._\-]{10,}$/'],
        'GOOGLE_CLIENT_ID' => ['label' => 'Google client ID', 'group' => 'google', 'hint' => 'Google Cloud Console → APIs & Services → Credentials → OAuth client ID (type: Web application)', 'pattern' => '/^[0-9]+-[A-Za-z0-9_]+\.apps\.googleusercontent\.com$/'],
        'GOOGLE_CLIENT_SECRET' => ['label' => 'Google client secret', 'group' => 'google', 'hint' => 'Shown next to the client ID, or under “Additional information”', 'pattern' => '/^\S[\s\S]{8,100}$/'],
        'FACEBOOK_CLIENT_ID' => ['label' => 'Facebook app ID', 'group' => 'facebook', 'hint' => 'developers.facebook.com → your app → Settings → Basic → App ID', 'pattern' => '/^[0-9]{5,20}$/'],
        'FACEBOOK_CLIENT_SECRET' => ['label' => 'Facebook app secret', 'group' => 'facebook', 'hint' => 'Same page → App Secret (click “Show”)', 'pattern' => '/^[A-Za-z0-9]{20,64}$/'],
        'X_CLIENT_ID' => ['label' => 'X (Twitter) client ID', 'group' => 'x', 'hint' => 'developer.x.com → your app → User authentication settings → OAuth 2.0 Client ID', 'pattern' => '/^[A-Za-z0-9_-]{10,100}$/'],
        'X_CLIENT_SECRET' => ['label' => 'X (Twitter) client secret', 'group' => 'x', 'hint' => 'Same page → Client Secret', 'pattern' => '/^[A-Za-z0-9_-]{10,100}$/'],
    ];

    private static ?string $key = null;
    private static array $cache = [];

    /** Effective value: newsroom-entered first, then .env, then the default. */
    public static function get(string $name, string $default = ''): string
    {
        if (!array_key_exists($name, self::$cache)) {
            self::$cache[$name] = self::stored($name);
        }
        $v = self::$cache[$name];
        return $v !== null && $v !== '' ? $v : Config::get($name, $default);
    }

    public static function set(string $name, string $value): void
    {
        $meta = self::FIELDS[$name] ?? null;
        if (!$meta) {
            throw new HttpException(400, 'Unknown setting');
        }
        $value = trim($value);
        if ($value === '') {
            self::clear($name);
            return;
        }
        if (!preg_match($meta['pattern'], $value)) {
            throw new HttpException(400, $meta['label'] . ' does not look right. ' . $meta['hint']);
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($value, $nonce, self::key());
        Database::setSetting('secret:' . $name, 'v1:' . base64_encode($nonce . $box));
        self::$cache[$name] = $value;
        if (str_starts_with($name, 'PAYPAL_')) {
            Database::setSetting('paypal_token', ''); // credentials changed: drop the cached OAuth token
        }
        if (str_starts_with($name, 'SMTP_') || str_starts_with($name, 'BREVO_')) {
            Database::setSetting('mail_last_error', ''); // give delivery a clean slate after a change
        }
    }

    public static function clear(string $name): void
    {
        Database::query('DELETE FROM settings WHERE key=?', ['secret:' . $name]);
        self::$cache[$name] = null;
    }

    /** Where each value comes from and a masked hint, never the value itself. */
    public static function status(): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $meta) {
            $panel = self::stored($name);
            $env = Config::get($name);
            $value = $panel ?: $env;
            $out[$name] = $meta + [
                'source' => $panel ? 'panel' : ($env !== '' ? 'env' : 'none'),
                'masked' => $value !== '' ? self::mask($value) : '',
                'set' => $value !== '',
            ];
        }
        return $out;
    }

    public static function mask(string $v): string
    {
        if (in_array($v, ['sandbox', 'live'], true)) {
            return $v;
        }
        $prefix = preg_match('/^([a-z]+_(?:live|test)_|whsec_|price_)/', $v, $m) ? $m[1] : '';
        return $prefix . '••••' . substr($v, -4);
    }

    private static function stored(string $name): ?string
    {
        if (!Database::installed()) {
            return null;
        }
        $raw = (string)Database::setting('secret:' . $name, '');
        if ($raw === '' || !str_starts_with($raw, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($raw, 3), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $appKey = Config::get('APP_KEY');
        if ($appKey !== '') {
            return self::$key = hash('sha256', 'menews-secrets|' . $appKey, true);
        }
        $file = Config::storage() . '/data/.secret_key';
        if (!is_file($file)) {
            $key = bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            if (@file_put_contents($file, $key, LOCK_EX) === false) {
                throw new HttpException(500, 'Cannot write the encryption key file in storage/data');
            }
            @chmod($file, 0600);
        }
        $hex = trim((string)file_get_contents($file));
        if (strlen($hex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            throw new HttpException(500, 'The encryption key file in storage/data is damaged');
        }
        return self::$key = hex2bin($hex);
    }

    /** Live check against each provider so an admin knows the keys work before a customer does. */
    public static function test(string $gateway): array
    {
        if ($gateway === 'stripe') {
            $key = self::get('STRIPE_SECRET_KEY');
            if ($key === '') {
                throw new HttpException(400, 'Enter a Stripe secret key first');
            }
            $acct = Remote::json('https://api.stripe.com/v1/account', null, ['Authorization: Bearer ' . $key], 'json', 20);
            if (!empty($acct['error'])) {
                throw new HttpException(400, 'Stripe rejected the key: ' . ($acct['error']['message'] ?? 'unknown error'));
            }
            $name = $acct['settings']['dashboard']['display_name'] ?? ($acct['business_profile']['name'] ?? ($acct['email'] ?? 'account'));
            return ['ok' => true, 'message' => 'Stripe connected: ' . $name . ' (' . (str_contains($key, '_live_') ? 'live' : 'test') . ' mode' . (self::get('STRIPE_WEBHOOK_SECRET') === '' ? ', webhook secret still missing' : '') . ')'];
        }
        if ($gateway === 'paypal') {
            if (self::get('PAYPAL_CLIENT_ID') === '' || self::get('PAYPAL_CLIENT_SECRET') === '') {
                throw new HttpException(400, 'Enter the PayPal client ID and secret first');
            }
            Database::setSetting('paypal_token', '');
            $base = self::get('PAYPAL_MODE', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
            $res = Remote::json($base . '/v1/oauth2/token', ['grant_type' => 'client_credentials'], ['Authorization: Basic ' . base64_encode(self::get('PAYPAL_CLIENT_ID') . ':' . self::get('PAYPAL_CLIENT_SECRET'))], 'form', 20);
            if (empty($res['access_token'])) {
                throw new HttpException(400, 'PayPal rejected the credentials: ' . ($res['error_description'] ?? $res['error'] ?? 'unknown error'));
            }
            return ['ok' => true, 'message' => 'PayPal connected (' . self::get('PAYPAL_MODE', 'sandbox') . ' mode' . (self::get('PAYPAL_WEBHOOK_ID') === '' ? ', webhook ID still missing' : '') . ')'];
        }
        if ($gateway === 'razorpay') {
            $id = self::get('RAZORPAY_KEY_ID');
            $secret = self::get('RAZORPAY_KEY_SECRET');
            if ($id === '' || $secret === '') {
                throw new HttpException(400, 'Enter the Razorpay key ID and secret first');
            }
            $res = Remote::json('https://api.razorpay.com/v1/payment_links?count=1', null, ['Authorization: Basic ' . base64_encode($id . ':' . $secret)], 'json', 20);
            return ['ok' => true, 'message' => 'Razorpay connected (' . (str_contains($id, '_live_') ? 'live' : 'test') . ' mode' . (self::get('RAZORPAY_WEBHOOK_SECRET') === '' ? ', webhook secret still missing' : '') . ')'];
        }
        if ($gateway === 'google') {
            $id = self::get('GOOGLE_CLIENT_ID');
            $secret = self::get('GOOGLE_CLIENT_SECRET');
            if ($id === '' || $secret === '') {
                throw new HttpException(400, 'Enter the Google client ID and secret first');
            }
            $res = self::probe('https://oauth2.googleapis.com/token', ['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'refresh_token', 'refresh_token' => 'menews-credential-probe']);
            if (($res['error'] ?? '') === 'invalid_client') {
                throw new HttpException(400, 'Google rejected the client ID/secret: ' . ($res['error_description'] ?? 'invalid_client'));
            }
            return ['ok' => true, 'message' => 'Google accepted the client ID and secret. Add ' . absolute_url('/auth/google/callback') . ' as an authorised redirect URI in Google Cloud Console, then “Continue with Google” is ready.'];
        }
        if ($gateway === 'facebook') {
            $id = self::get('FACEBOOK_CLIENT_ID');
            $secret = self::get('FACEBOOK_CLIENT_SECRET');
            if ($id === '' || $secret === '') {
                throw new HttpException(400, 'Enter the Facebook app ID and secret first');
            }
            $res = self::probe('https://graph.facebook.com/oauth/access_token?' . http_build_query(['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials']), null);
            if (empty($res['access_token'])) {
                throw new HttpException(400, 'Facebook rejected the app ID/secret: ' . ($res['error']['message'] ?? 'unknown error'));
            }
            return ['ok' => true, 'message' => 'Facebook verified the app ID and secret. Add ' . absolute_url('/auth/facebook/callback') . ' under Valid OAuth Redirect URIs, then “Continue with Facebook” is ready.'];
        }
        if ($gateway === 'x') {
            if (self::get('X_CLIENT_ID') === '' || self::get('X_CLIENT_SECRET') === '') {
                throw new HttpException(400, 'Enter the X client ID and secret first');
            }
            return ['ok' => true, 'message' => 'Saved. X has no way to verify credentials without a real sign-in — add ' . absolute_url('/auth/x/callback') . ' as a callback URL in the X developer portal, then try “Continue with X” yourself to confirm.'];
        }
        if ($gateway === 'brevo') {
            $key = self::get('BREVO_API_KEY');
            if ($key === '') {
                throw new HttpException(400, 'Enter a Brevo API key first');
            }
            $acct = Remote::json('https://api.brevo.com/v3/account', null, ['api-key: ' . $key, 'accept: application/json'], 'json', 20);
            $name = trim(($acct['companyName'] ?? '') ?: ($acct['email'] ?? 'account'));
            return ['ok' => true, 'message' => 'Brevo connected: ' . $name . ($acct['plan'][0]['credits'] ?? null ? ' · ' . (int)$acct['plan'][0]['credits'] . ' credits' : '')];
        }
        throw new HttpException(400, 'Unknown gateway');
    }

    /**
     * Like Remote::json(), but returns the decoded body even on a non-2xx response —
     * OAuth token endpoints put the useful "invalid_client" vs "invalid_grant" distinction
     * in a JSON body sent alongside a 400/401, which Remote::json() would otherwise discard.
     */
    private static function probe(string $url, ?array $form): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(502, 'Unable to initialise HTTP client');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new HttpException(502, 'Could not reach the provider: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : [];
    }
}
