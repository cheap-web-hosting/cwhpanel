<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Foundation\Database\Connection;

class Audit
{
    private Connection $db;

    private ?Session $session = null;

    private ?int $userId = null;

    public function __construct(Connection $db, ?Session $session = null)
    {
        $this->db = $db;
        $this->session = $session;
    }

    public function setUser(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function log(string $action, ?string $resourceType = null, $resourceId = null, array $metadata = []): void
    {
        $request = null;

        try {
            $request = app('request');
        } catch (\Throwable $e) {
            // CLI context has no request.
        }

        $userId = $this->userId;
        if ($userId === null && $this->session && $this->session->isStarted()) {
            $userId = $this->session->get('user_id');
        }

        $ip = ($request && PHP_SAPI !== 'cli') ? $request->ip() : 'cli';
        $userAgent = ($request && PHP_SAPI !== 'cli') ? $request->userAgent() : 'cli';

        try {
            $this->db->insert('audit_logs', [
                'user_id' => $userId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId !== null ? (string) $resourceId : null,
                'ip_address' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 255),
                'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Failed to write audit log: ' . $e->getMessage());
        }
    }
}
