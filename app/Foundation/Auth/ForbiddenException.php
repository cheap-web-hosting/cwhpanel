<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

class ForbiddenException extends \RuntimeException
{
    private int $statusCode = 403;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
