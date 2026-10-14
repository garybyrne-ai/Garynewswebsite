<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Controllers\AccountController;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;

/**
 * "Continue with Google / Facebook / X": the whole OAuth 2.0 round trip, in one place.
 * No vendor SDKs — same raw Remote::json() approach already used for PayPal's own OAuth2
 * client-credentials flow. A CSRF `state` (double-submit cookie) guards every provider; X's
 * confidential-client flow additionally gets PKCE, which its authorization server requires.
 */
final class OAuth
{
    private const PROVIDERS = [
        'google' => [
            'label' => 'Google',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email profile',
            'pkce' => false,
        ],
        'facebook' => [
            'label' => 'Facebook',
            'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'scope' => 'email public_profile',
            'pkce' => false,
        ],
        'x' => [
            'label' => 'X',
            'authorize_url' => 'https://twitter.com/i/oauth2/authorize',
            'token_url' => 'https://api.twitter.com/2/oauth2/token',
            'scope' => 'users.read tweet.read',
            'pkce' => true,
        ],
    ];

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? ucfirst($provider);
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /** Has a client ID and secret entered in the newsroom (or .env) — ready to offer to readers. */
    public static function enabled(string $provider): bool
    {
        if (!self::isKnown($provider)) {
            return false;
        }
        $p = strtoupper($provider);
        return Secrets::get($p . '_CLIENT_ID') !== '' && Secrets::get($p . '_CLIENT_SECRET') !== '';
    }

    /** Providers with usable credentials right now, for the sign-in modal. */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), [self::class, 'enabled']));
    }

    public static function redirectUri(string $provider): string
    {
        return absolute_url('/auth/' . $provider . '/callback');
    }

    /** Build the provider's consent-screen URL and set the short-lived state/PKCE cookies. */
    public static function authorizeUrl(string $provider, Request $r): string
    {
        $cfg = self::config($provider);
        if (!self::enabled($provider)) {
            throw new HttpException(404, self::label($provider) . ' sign-in is not set up yet.');
        }
        $state = bin2hex(random_bytes(24));
        self::setShortCookie($r, 'oauth_state', $state);
        $params = [
            'client_id' => Secrets::get(strtoupper($provider) . '_CLIENT_ID'),
            'redirect_uri' => self::redirectUri($provider),
            'response_type' => 'code',
            'scope' => $cfg['scope'],
            'state' => $state,
        ];
        if ($provider === 'google') {
            $params['access_type'] = 'online';
            $params['prompt'] = 'select_account';
        }
        if ($cfg['pkce']) {
            $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
            self::setShortCookie($r, 'oauth_pkce', $verifier);
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }
        return $cfg['authorize_url'] . '?' . http_build_query($params);
    }

    /**
     * The callback: verify `state`, exchange the code for a token, fetch the profile, and
     * find/create/link the local account. Returns the users row, ready for Auth::login().
     */
    public static function completeLogin(string $provider, Request $r, string $code, string $state): array
    {
        $cfg = self::config($provider);
        $cookieState = $r->cookie('oauth_state');
        $verifier = $r->cookie('oauth_pkce');
        self::clearShortCookie($r, 'oauth_state'); // single-use: valid for exactly one callback
        self::clearShortCookie($r, 'oauth_pkce');
        if ($code === '' || $state === '' || $cookieState === '' || !hash_equals($cookieState, $state)) {
            throw new HttpException(400, 'That sign-in link expired or was already used. Please try again.');
        }
        $clientId = Secrets::get(strtoupper($provider) . '_CLIENT_ID');
        $clientSecret = Secrets::get(strtoupper($provider) . '_CLIENT_SECRET');
        if ($clientId === '' || $clientSecret === '') {
            throw new HttpException(404, self::label($provider) . ' sign-in is not set up yet.');
        }
        $body = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => self::redirectUri($provider),
            'grant_type' => 'authorization_code',
        ];
        if ($cfg['pkce']) {
            if ($verifier === '') {
                throw new HttpException(400, 'That sign-in link expired. Please try again.');
            }
            $body['code_verifier'] = $verifier;
        }
        $headers = $provider === 'x' ? ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)] : [];
        try {
            $token = Remote::json($cfg['token_url'], $body, $headers, 'form', 20);
        } catch (\Throwable) {
            throw new HttpException(400, self::label($provider) . ' rejected that sign-in attempt. Please try again.');
        }
        $access = $token['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new HttpException(400, self::label($provider) . ' did not return an access token.');
        }
        $profile = self::fetchProfile($provider, $access);
        return self::findOrCreateUser($provider, $profile, $r);
    }

    /** Normalise each provider's own profile shape to a common {id,name,email,email_verified}. */
    private static function fetchProfile(string $provider, string $accessToken): array
    {
        if ($provider === 'google') {
            $p = Remote::json('https://www.googleapis.com/oauth2/v3/userinfo', null, ['Authorization: Bearer ' . $accessToken], 'json', 20);
            return [
                'id' => (string)($p['sub'] ?? ''),
                'name' => (string)($p['name'] ?? 'Google user'),
                'email' => isset($p['email']) ? mb_strtolower((string)$p['email']) : null,
                'email_verified' => (bool)($p['email_verified'] ?? false),
            ];
        }
        if ($provider === 'facebook') {
            $p = Remote::json('https://graph.facebook.com/me?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $accessToken]), null, [], 'json', 20);
            return [
                'id' => (string)($p['id'] ?? ''),
                'name' => (string)($p['name'] ?? 'Facebook user'),
                // Facebook's Graph API only ever hands back `email` when it is verified and granted.
                'email' => isset($p['email']) ? mb_strtolower((string)$p['email']) : null,
                'email_verified' => isset($p['email']),
            ];
        }
        // x: the standard API tier does not grant access to the member's email address at all.
        $p = Remote::json('https://api.twitter.com/2/users/me', null, ['Authorization: Bearer ' . $accessToken], 'json', 20);
        $data = $p['data'] ?? [];
        return [
            'id' => (string)($data['id'] ?? ''),
            'name' => (string)($data['name'] ?? 'X user'),
            'email' => null,
            'email_verified' => false,
        ];
    }

    /**
     * Link to an existing identity for this provider, or an existing password account
     * (only when the provider itself vouches the email is verified), or create a new member.
     */
    private static function findOrCreateUser(string $provider, array $profile, Request $r): array
    {
        if ($profile['id'] === '') {
            throw new HttpException(400, self::label($provider) . ' did not return an account id.');
        }
        $linked = Database::one('SELECT u.* FROM oauth_identities o JOIN users u ON u.id=o.user_id WHERE o.provider=? AND o.provider_user_id=?', [$provider, $profile['id']]);
        if ($linked) {
            return $linked;
        }
        return Database::transaction(static function () use ($provider, $profile, $r): array {
            // Never trust an unverified email claim for account linking — that would let anyone
            // take over an existing password account just by typing someone else's address.
            $verifiedEmail = ($profile['email'] && $profile['email_verified']) ? $profile['email'] : null;
            $user = $verifiedEmail ? Database::one('SELECT * FROM users WHERE email=?', [$verifiedEmail]) : null;
            if (!$user) {
                $id = uuid();
                $name = trim($profile['name']) ?: (ucfirst($provider) . ' user');
                Database::insert('users', [
                    'id' => $id,
                    'email' => $verifiedEmail ?? ($provider . '-' . $profile['id'] . '@users.invalid'),
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    'display_name' => mb_substr($name, 0, 80),
                    'handle' => AccountController::uniqueHandle($name),
                    'accent' => (string)random_int(0, 359),
                    'created_at' => now(),
                    'registration_ip' => $r->ip(), 'registration_user_agent' => $r->userAgent(),
                ]);
                // NULL, never '': subscriptions.email is UNIQUE and SQLite allows many NULLs but only one ''.
                Database::insert('subscriptions', ['user_id' => $id, 'email' => $verifiedEmail, 'plan' => 'free', 'status' => 'active', 'created_at' => now()]);
                $user = Database::one('SELECT * FROM users WHERE id=?', [$id]);
                Notifier::send($id, 'welcome', 'Welcome to Bharat Wire', 'Follow your local area and report what is happening around you.');
            }
            Database::insert('oauth_identities', [
                'provider' => $provider,
                'provider_user_id' => $profile['id'],
                'user_id' => $user['id'],
                'email' => $profile['email'],
                'created_at' => now(),
            ]);
            return $user;
        });
    }

    private static function config(string $provider): array
    {
        $cfg = self::PROVIDERS[$provider] ?? null;
        if (!$cfg) {
            throw new HttpException(404, 'Unknown sign-in provider');
        }
        return $cfg;
    }

    /** Short-lived, httponly, path-scoped cookie for the state/PKCE round trip. */
    private static function setShortCookie(Request $r, string $name, string $value): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => time() + 600,
            'path' => '/auth',
            'secure' => $r->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearShortCookie(Request $r, string $name): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/auth',
            'secure' => $r->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
