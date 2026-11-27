<?php

declare(strict_types=1);

namespace Modules\Authorization\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;

class RoleController
{
    public function index(): Response
    {
        $roles = app('db')->select(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM `role_permissions` rp WHERE rp.`role_id` = r.`id`) AS `permission_count`,
                    (SELECT COUNT(*) FROM `users` u WHERE u.`role_id` = r.`id`) AS `user_count`
             FROM `roles` r ORDER BY r.`is_system` DESC, r.`name`'
        );

        $content = view('admin/access/roles', [
            'title' => 'Roles',
            'roles' => $roles,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function show(int $id): Response
    {
        $role = app('db')->selectOne('SELECT * FROM `roles` WHERE `id` = ?', [$id]);

        if ($role === null) {
            return Response::redirect('/admin/access/roles');
        }

        $allPermissions = app('db')->select('SELECT * FROM `permissions` ORDER BY `module`, `name`');
        $assigned = app('db')->select('SELECT `permission_id` FROM `role_permissions` WHERE `role_id` = ?', [$id]);
        $assignedIds = array_map(fn ($r) => (int) $r['permission_id'], $assigned);

        $grouped = [];
        foreach ($allPermissions as $p) {
            $grouped[$p['module']][] = $p;
        }

        $content = view('admin/access/role-edit', [
            'title' => 'Role: ' . $role['name'],
            'role' => $role,
            'groupedPermissions' => $grouped,
            'assignedIds' => $assignedIds,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function update(int $id, Request $request): Response
    {
        $role = app('db')->selectOne('SELECT * FROM `roles` WHERE `id` = ?', [$id]);

        if ($role === null) {
            return Response::redirect('/admin/access/roles');
        }

        if ((bool) $role['is_system']) {
            app('session')->flash('error', 'System roles cannot be modified.');
            return Response::redirect('/admin/access/roles');
        }

        $permissionIds = array_values(array_unique(array_map('intval', (array) $request->input('permissions', []))));

        // Drop forged/unknown ids (no FK on the table): only real permission
        // rows may be linked, else dead rows accumulate and Gate lookups
        // join against nothing.
        if ($permissionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
            $valid = app('db')->select("SELECT `id` FROM `permissions` WHERE `id` IN ({$placeholders})", $permissionIds);
            $permissionIds = array_map(fn ($r) => (int) $r['id'], $valid);
        }

        // A role editor must not grant permissions they do not themselves
        // hold — otherwise access.roles.edit is a self-escalation to full admin.
        $actor = app('auth')->user();
        $gate = app('gate');
        if (!$gate->isFullAdmin($actor) && $permissionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
            $names = app('db')->select("SELECT `name` FROM `permissions` WHERE `id` IN ({$placeholders})", $permissionIds);
            foreach ($names as $n) {
                if (!$gate->can($actor, (string) $n['name'])) {
                    app('session')->flash('error', 'You cannot grant a permission you do not hold (' . $n['name'] . ').');
                    return Response::redirect('/admin/access/roles/' . $id);
                }
            }
        }

        try {
            app('db')->transaction(function ($db) use ($id, $permissionIds) {
                $db->delete('role_permissions', '`role_id` = ?', [$id]);

                foreach ($permissionIds as $permissionId) {
                    $db->insert('role_permissions', [
                        'role_id' => $id,
                        'permission_id' => $permissionId,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Role permissions could not be saved.');
            return Response::redirect('/admin/access/roles/' . $id);
        }

        audit('authorization.role.updated', 'role', $id, ['permissions' => count($permissionIds)]);
        app('session')->flash('success', 'Role permissions updated.');

        return Response::redirect('/admin/access/roles/' . $id);
    }

    /**
     * Create a custom (non-system) role. Name is free text; slug is the
     * URL-safe key (lowercase, digits, dashes — same charset as usernames
     * so it round-trips through URLs safely). Slugs are unique; the three
     * system slugs (superadmin/admin/customer) are reserved.
     */
    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $slug = strtolower(trim((string) $request->input('slug', '')));

        if ($name === '' || strlen($name) > 191) {
            app('session')->flash('error', 'Role name is required (max 191 characters).');
            return Response::redirect('/admin/access/roles');
        }

        if (preg_match('/^[a-z0-9-]{1,64}$/', $slug) !== 1) {
            app('session')->flash('error', 'Slug must be 1-64 chars: lowercase letters, digits, dashes.');
            return Response::redirect('/admin/access/roles');
        }

        if (app('db')->selectOne('SELECT `id` FROM `roles` WHERE `slug` = ?', [$slug]) !== null) {
            app('session')->flash('error', 'A role with that slug already exists.');
            return Response::redirect('/admin/access/roles');
        }

        $id = app('db')->insert('roles', [
            'name' => $name,
            'slug' => $slug,
            'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        audit('authorization.role.created', 'role', $id, ['slug' => $slug]);
        app('session')->flash('success', 'Role created — assign its permissions on the next page.');

        return Response::redirect('/admin/access/roles/' . $id);
    }

    /**
     * Delete a custom role. Refused for system roles, for roles with users
     * attached (reassign them first — never orphan a login's role), and
     * for the role of the admin performing the delete.
     */
    public function destroy(int $id): Response
    {
        $role = app('db')->selectOne('SELECT * FROM `roles` WHERE `id` = ?', [$id]);

        if ($role === null) {
            return Response::redirect('/admin/access/roles');
        }

        if ((bool) $role['is_system']) {
            app('session')->flash('error', 'System roles cannot be deleted.');
            return Response::redirect('/admin/access/roles');
        }

        $me = app('auth')->user();

        if ($me !== null && $me->roleId === $id) {
            app('session')->flash('error', 'You cannot delete your own role.');
            return Response::redirect('/admin/access/roles/' . $id);
        }

        $users = (int) (app('db')->selectOne('SELECT COUNT(*) AS `n` FROM `users` WHERE `role_id` = ?', [$id])['n'] ?? 0);

        if ($users > 0) {
            app('session')->flash('error', "Role has {$users} user(s) attached — reassign them first.");
            return Response::redirect('/admin/access/roles/' . $id);
        }

        app('db')->transaction(function ($db) use ($id) {
            $db->delete('role_permissions', '`role_id` = ?', [$id]);
            $db->delete('roles', '`id` = ?', [$id]);
        });

        audit('authorization.role.deleted', 'role', $id, ['slug' => $role['slug']]);
        app('session')->flash('success', 'Role deleted.');

        return Response::redirect('/admin/access/roles');
    }
}
