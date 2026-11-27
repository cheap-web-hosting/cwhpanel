<?php

declare(strict_types=1);

namespace App\Foundation\Events;

class Event
{
    private string $name;

    private array $payload;

    private bool $halted = false;

    public function __construct(string $name, array $payload = [])
    {
        $this->name = $name;
        $this->payload = $payload;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function set(string $key, $value): self
    {
        $this->payload[$key] = $value;
        return $this;
    }

    public function halt(): void
    {
        $this->halted = true;
    }

    public function isHalted(): bool
    {
        return $this->halted;
    }
}
