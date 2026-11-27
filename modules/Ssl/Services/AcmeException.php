<?php

declare(strict_types=1);

namespace Modules\Ssl\Services;

/**
 * ACME protocol error with rate-limit metadata.
 *
 * Carries the raw HTTP status (e.g. 429), the ACME problem `type` (e.g.
 * `urn:ietf:params:acme:error:rateLimited`), the problem `detail` message, and
 * an optional `Retry-After` (in seconds) parsed from the response headers so
 * callers can back off instead of blind re-issuing.
 */
class AcmeException extends \RuntimeException
{
    public readonly int $status;

    public readonly ?string $type;

    public readonly ?int $retryAfterSeconds;

    public function __construct(string $message, int $status = 0, ?string $type = null, ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->type = $type;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429
            || str_contains((string) $this->type, 'rateLimited');
    }
}