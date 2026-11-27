<?php

declare(strict_types=1);

namespace App\Foundation\Module;

class Manifest
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function id(): ?string
    {
        return $this->get('id');
    }

    public function name(): ?string
    {
        return $this->get('name');
    }

    public function version(): ?string
    {
        return $this->get('version');
    }

    public function admin(): bool
    {
        return (bool) $this->get('admin', false);
    }

    public function customer(): bool
    {
        return (bool) $this->get('customer', false);
    }

    public function requires(): array
    {
        return (array) $this->get('requires', []);
    }

    public function permissions(): array
    {
        return (array) $this->get('permissions', []);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
