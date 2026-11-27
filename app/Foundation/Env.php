<?php

declare(strict_types=1);

namespace App\Foundation;

class Env
{
    private array $values = [];

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function load(string $file): self
    {
        $values = [];

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (!str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }

                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function get(string $key, $default = null)
    {
        // Real OS environment variables take precedence over the .env file,
        // mirroring standard behavior (and enabling per-process overrides).
        $env = getenv($key);

        if ($env !== false) {
            return $this->coerce($env);
        }

        if (isset($this->values[$key])) {
            return $this->coerce($this->values[$key]);
        }

        return $default;
    }

    private function coerce($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
