<?php

declare(strict_types=1);

namespace App\Foundation;

class Csrf
{
    private Session $session;

    private string $tokenKey = '_csrf_token';

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function token(): string
    {
        if (!$this->session->has($this->tokenKey)) {
            $this->session->put($this->tokenKey, $this->generate());
        }

        return (string) $this->session->get($this->tokenKey);
    }

    public function validate(string $token): bool
    {
        $expected = $this->session->get($this->tokenKey);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $token);
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
