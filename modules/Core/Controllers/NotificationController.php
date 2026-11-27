<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Core\Services\NotificationService;

class NotificationController
{
    private NotificationService $service;

    public function __construct(NotificationService $service)
    {
        $this->service = $service;
    }

    private function userOr401(): ?Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::json(['error' => 'Unauthenticated.'], 401);
        }
        return null;
    }

    public function index(Request $request): Response
    {
        $blocked = $this->userOr401();
        if ($blocked !== null) {
            return $blocked;
        }
        $user = app('auth')->user();
        $unread = $this->service->recent((int) $user->id, 25);
        foreach ($unread as &$n) {
            $n['read'] = $n['read_at'] !== null;
        }
        unset($n);
        return Response::json([
            'unread_count' => $this->service->unreadCount((int) $user->id),
            'notifications' => array_values($unread),
        ]);
    }

    public function read(Request $request, ?int $id = null): Response
    {
        $blocked = $this->userOr401();
        if ($blocked !== null) {
            return $blocked;
        }
        $user = app('auth')->user();
        if ($id !== null) {
            $this->service->markRead((int) $user->id, $id);
        }
        return Response::json(['ok' => true]);
    }

    public function readAll(Request $request): Response
    {
        $blocked = $this->userOr401();
        if ($blocked !== null) {
            return $blocked;
        }
        $user = app('auth')->user();
        $n = $this->service->markAllRead((int) $user->id);
        return Response::json(['ok' => true, 'marked' => $n]);
    }
}
