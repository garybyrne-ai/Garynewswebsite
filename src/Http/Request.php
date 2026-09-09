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

    public function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
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
