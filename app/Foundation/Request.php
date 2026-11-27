<?php

declare(strict_types=1);

namespace App\Foundation;

class Request
{
    private array $query;

    private array $request;

    private array $files;

    private array $server;

    private array $cookies;

    private ?array $json = null;

    private string $method;

    private string $path;

    public function __construct(
        ?array $query = null,
        ?array $request = null,
        ?array $files = null,
        ?array $server = null,
        ?array $cookies = null
    ) {
        $this->query = $query ?? $_GET;
        $this->request = $request ?? $_POST;
        $this->files = $files ?? $_FILES;
        $this->server = $server ?? $_SERVER;
        $this->cookies = $cookies ?? $_COOKIE;

        $this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        $this->path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->all();
        }

        return $this->request[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->request;
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }
        return $out;
    }

    public function json(?string $key = null, $default = null)
    {
        if ($this->json === null) {
            $raw = file_get_contents('php://input') ?: '';
            $this->json = json_decode($raw, true) ?: [];
        }

        if ($key === null) {
            return $this->json;
        }

        return $this->json[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$name] ?? null;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function expectsJson(): bool
    {
        return str_contains($this->header('Accept') ?? '', 'application/json')
            || str_contains($this->path(), '/api/');
    }

    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off';
    }

    public function fullUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . ($this->server['REQUEST_URI'] ?? '/');
    }

    public function validate(array $rules): array
    {
        return app(Validator::class)->validate($this->all(), $rules);
    }
}
