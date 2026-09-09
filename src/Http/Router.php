<?php
declare(strict_types=1);

namespace MeNews\Http;

/**
 * Tiny pattern router. Patterns use {name} placeholders which match a single path segment
 * ([^/]+) unless a custom regex is supplied as {name:regex}.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '~^' . $regex . '$~u', 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($route['method'] === 'GET' && $request->method === 'HEAD')) {
                $methodMatched = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $result = ($route['handler'])($request, $params);
            return $result instanceof Response ? $result : Response::json($result);
        }
        throw new HttpException($methodMatched ? 405 : 404, $methodMatched ? 'Method not allowed' : 'Not found');
    }
}
