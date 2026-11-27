<?php

declare(strict_types=1);

namespace Modules\Authorization\Controllers;

use App\Foundation\Response;

class PermissionController
{
    public function index(): Response
    {
        $permissions = app('db')->select('SELECT * FROM `permissions` ORDER BY `module`, `name`');

        $grouped = [];
        foreach ($permissions as $p) {
            $grouped[$p['module']][] = $p;
        }

        $content = view('admin/access/permissions', [
            'title' => 'Permissions',
            'groupedPermissions' => $grouped,
        ], 'layouts/admin');

        return new Response((string) $content);
    }
}
