<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

class User
{
    public ?int $id;

    public string $email;

    public string $name;

    public ?int $roleId;

    public bool $isAdmin;

    public string $status;

    public ?string $lastLoginAt;

    public ?string $twoFactorSecret;

    public bool $twoFactorEnabled;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'] ?? null;
        $this->email = (string) ($attributes['email'] ?? '');
        $this->name = (string) ($attributes['name'] ?? '');
        $this->roleId = isset($attributes['role_id']) ? (int) $attributes['role_id'] : null;
        $this->isAdmin = (bool) ($attributes['is_admin'] ?? false);
        $this->status = (string) ($attributes['status'] ?? 'active');
        $this->lastLoginAt = $attributes['last_login_at'] ?? null;
        $this->twoFactorSecret = $attributes['two_factor_secret'] ?? null;
        $this->twoFactorEnabled = (bool) ($attributes['two_factor_enabled'] ?? false);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function can(string $permission): bool
    {
        return app('gate')->can($this, $permission);
    }
}
