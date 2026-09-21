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
        'STRIPE_PRICE_ME_PLUS' => ['label' => 'Stripe price ID for monthly ME+ (optional)', 'group' => 'stripe', 'hint' => 'price_… — leave blank to bill the price from Settings', 'pattern' => '/^price_[A-Za-z0-9]{6,}$/'],
        'PAYPAL_CLIENT_ID' => ['label' => 'PayPal client ID', 'group' => 'paypal', 'hint' => 'From the PayPal developer dashboard → your REST app', 'pattern' => '/^[A-Za-z0-9_\-]{20,}$/'],
        'PAYPAL_CLIENT_SECRET' => ['label' => 'PayPal client secret', 'group' => 'paypal', 'hint' => 'Same app, “Secret key”', 'pattern' => '/^[A-Za-z0-9_\-]{20,}$/'],
        'PAYPAL_WEBHOOK_ID' => ['label' => 'PayPal webhook ID', 'group' => 'paypal', 'hint' => 'Shown after you add the webhook URL to the app', 'pattern' => '/^[A-Z0-9]{10,}$/'],
        'PAYPAL_MODE' => ['label' => 'PayPal mode', 'group' => 'paypal', 'hint' => 'sandbox while testing, live when ready', 'pattern' => '/^(sandbox|live)$/'],
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
        throw new HttpException(400, 'Unknown gateway');
    }
}
