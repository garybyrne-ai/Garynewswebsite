<?php
declare(strict_types=1);

namespace MeNews\Http;

final class Request
{
    public readonly string $method;
    public readonly string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $path = preg_replace('~/+~', '/', $path) ?? '/';
        $this->path = ($path !== '/' ? rtrim($path, '/') : '/') ?: '/';
    }

    public function query(string $key, string $default = '', int $max = 500): string
    {
        $v = $_GET[$key] ?? $default;
        if (!is_string($v)) {
            throw new HttpException(422, 'Invalid query parameter: ' . $key);
        }
        return mb_substr(trim($v), 0, $max);
    }

    public function int(string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $v = $this->query($key, (string)$default);
        return max($min, min($max, (int)$v));
    }

    public function post(string $key, string $default = '', int $max = 6000): string
    {
        $v = $_POST[$key] ?? $default;
        if (!is_string($v)) {
            throw new HttpException(422, 'Invalid field: ' . $key);
        }
        return mb_substr(trim($v), 0, $max);
    }

    public function rawPost(string $key): mixed
    {
        return $_POST[$key] ?? null;
    }

    public function file(string $key): ?array
    {
        if (!isset($_FILES[$key]) || ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $_FILES[$key];
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER[$key] ?? '');
    }

    public function bearer(): string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : '';
    }

    public function cookie(string $name): string
    {
        $v = $_COOKIE[$name] ?? '';
        return is_string($v) ? $v : '';
    }

    /**
     * Visitor IP. Trusts X-Forwarded-For only when TRUST_PROXY_HEADERS=true (set this when
     * the app sits behind a reverse proxy/load balancer you control — Cloudways, Cloudflare,
     * nginx — that itself sets/overwrites the header; never enable it if the app is reachable
     * directly, or a client can simply forge its own IP for every logged action).
     */
    public function ip(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (\MeNews\Config::bool('TRUST_PROXY_HEADERS', false)) {
            $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    $ip = $first;
                }
            }
        }
        return $ip;
    }

    /** Browser/client User-Agent string, truncated to a sane storage length. */
    public function userAgent(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** True when the request was made by our own JavaScript client (CSRF guard for cookie sessions). */
    public function isFetch(): bool
    {
        return $this->header('X-Requested-With') === 'MENews';
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_contains($this->header('Accept'), 'application/json');
    }

    public function body(): string
    {
        return (string)file_get_contents('php://input');
    }
}
