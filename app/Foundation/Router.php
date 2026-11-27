<?php

declare(strict_types=1);

namespace App\Foundation;

use Closure;

class Route
{
    public string $method;

    public string $uri;

    public $handler;

    public array $middleware;

    public array $params = [];

    public function __construct(string $method, string $uri, $handler, array $middleware = [])
    {
        $this->method = strtoupper($method);
        $this->uri = '/' . trim($uri, '/');
        $this->handler = $handler;
        $this->middleware = $middleware;
    }

    /**
     * Append a middleware to THIS route only (unlike Router::middleware(),
     * which targets the active group).
     */
    public function withMiddleware(string $name): self
    {
        $this->middleware[] = $name;
        return $this;
    }
}

class Router
{
    private array $routes = [];

    private array $groupStack = [];

    private array $middlewareRegistry = [];

    public function __construct(array $middlewareRegistry = [])
    {
        $this->middlewareRegistry = $middlewareRegistry;
    }

    public function get(string $uri, $handler): Route
    {
        return $this->add('GET', $uri, $handler);
    }

    public function post(string $uri, $handler): Route
    {
        return $this->add('POST', $uri, $handler);
    }

    public function put(string $uri, $handler): Route
    {
        return $this->add('PUT', $uri, $handler);
    }

    public function patch(string $uri, $handler): Route
    {
        return $this->add('PATCH', $uri, $handler);
    }

    public function delete(string $uri, $handler): Route
    {
        return $this->add('DELETE', $uri, $handler);
    }

    public function add(string $method, string $uri, $handler): Route
    {
        $middleware = [];
        $prefix = '';

        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $uri = '/' . trim($prefix . '/' . trim($uri, '/'), '/');

        $route = new Route($method, $uri, $handler, $middleware);
        $this->routes[] = $route;

        return $route;
    }

    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = [
            'prefix' => $attributes['prefix'] ?? '',
            'middleware' => $attributes['middleware'] ?? [],
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    public function middleware(string $name): Route
    {
        $uri = '/*middleware-' . count($this->routes);

        $group = array_pop($this->groupStack);
        $group['middleware'][] = $name;
        $this->groupStack[] = $group;

        return new Route('ANY', $uri, fn () => null, [$name]);
    }

    public function match(string $method, string $path): ?Route
    {
        $path = '/' . trim($path, '/');

        foreach ($this->routes as $route) {
            if ($route->method !== strtoupper($method)) {
                continue;
            }

            $pattern = $this->compile($route->uri);

            if (preg_match($pattern, $path, $matches)) {
                $route->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $route;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->routes;
    }

    public function middlewareRegistry(): array
    {
        return $this->middlewareRegistry;
    }

    private function compile(string $uri): string
    {
        $uri = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $uri);

        return '#^' . $uri . '$#';
    }
}
