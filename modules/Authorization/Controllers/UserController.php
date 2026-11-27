<?php

declare(strict_types=1);

namespace Modules\Authorization\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;

/**
 * Admin login accounts (users with is_admin=1).
 *
 * Distinct from customer logins (created implicitly by account
 * provisioning): these are the panel operators a restricted role applies
 * to. Only admins reach these routes at all; role assignment here is what
 * makes custom roles meaningful — and what makes the delete-self guards
 * in RoleController load-bearing.
 */
class UserController
{
    public function index(): Response
    {
        $users = app('db')->select(
            'SELECT u.*, r.`name` AS `role_name`, r.`slug` AS `role_slug`
             FROM `users` u LEFT JOIN `roles` r ON r.`id` = u.`role_id`
             WHERE u.`is_admin` = 1 ORDER BY u.`email`'
        );

        $content = view('admin/access/users', [
            'title' => 'Admin Users',
            'users' => $users,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $roles = app('db')->select('SELECT * FROM `roles` WHERE `is_system` = 0 OR `slug` IN (?, ?) ORDER BY `is_system` DESC, `name`', ['superadmin', 'admin']);

        $content = view('admin/access/user-form', [
            'title' => 'Add Admin User',
            'user' => null,
            'roles' => $roles,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    /**
     * Create an admin login. The role decides everything past this point:
     * is_admin=1 gets them through the auth.admin gate, and the role's
     * permissions decide which sections survive. Emails are unique
     * panel-wide (shared with customer logins).
     */
    public function store(Request $request): Response
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $name = trim((string) $request->input('name', ''));
        $password = (string) $request->input('password', '');
        $roleId = (int) $request->input('role_id', 0);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            app('session')->flash('error', 'A valid email address is required.');
            return Response::redirect('/admin/access/users/create');
        }

        if ($name === '' || strlen($name) > 191) {
            app('session')->flash('error', 'A display name is required (max 191 characters).');
            return Response::redirect('/admin/access/users/create');
        }

        if (strlen($password) < (int) config('auth.min_password_length', 10)) {
            app('session')->flash('error', 'Password must be at least ' . config('auth.min_password_length', 10) . ' characters.');
            return Response::redirect('/admin/access/users/create');
        }

        $role = app('db')->selectOne('SELECT * FROM `roles` WHERE `id` = ?', [$roleId]);

        if ($role === null) {
            app('session')->flash('error', 'Unknown role.');
            return Response::redirect('/admin/access/users/create');
        }

        // A limited admin may not mint a login more powerful than themselves.
        $actor = app('auth')->user();
        $gate = app('gate');
        if (!$gate->isFullAdmin($actor)) {
            if (($role['slug'] ?? '') === 'superadmin' || !$gate->roleWithinActor($actor, $roleId)) {
                app('session')->flash('error', 'You cannot create a user with a role broader than your own.');
                return Response::redirect('/admin/access/users/create');
            }
        }

        if (app('db')->selectOne('SELECT `id` FROM `users` WHERE `email` = ?', [$email]) !== null) {
            app('session')->flash('error', 'That email is already used by another login.');
            return Response::redirect('/admin/access/users/create');
        }

        $id = app('db')->insert('users', [
            'email' => $email,
            'password' => hash_password($password),
            'name' => $name,
            'role_id' => $roleId,
            'is_admin' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        audit('authorization.user.created', 'user', $id, ['email' => $email, 'role' => $role['slug']]);
        app('session')->flash('success', 'Admin user created.');

        return Response::redirect('/admin/access/users');
    }

    public function edit(int $id): Response
    {
        $user = app('db')->selectOne('SELECT * FROM `users` WHERE `id` = ? AND `is_admin` = 1', [$id]);

        if ($user === null) {
            return Response::redirect('/admin/access/users');
        }

        $roles = app('db')->select('SELECT * FROM `roles` WHERE `is_system` = 0 OR `slug` IN (?, ?) ORDER BY `is_system` DESC, `name`', ['superadmin', 'admin']);

        $content = view('admin/access/user-form', [
            'title' => 'Edit Admin: ' . $user['email'],
            'user' => $user,
            'roles' => $roles,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    /**
     * Change role, status, and (optionally) password. Role changes take
     * effect on the target's next request (permissions are read live, no
     * session invalidation needed). Demoting/deactivating yourself is
     * refused — the panel must always have a way back in.
     */
    public function update(int $id, Request $request): Response
    {
        $user = app('db')->selectOne('SELECT * FROM `users` WHERE `id` = ? AND `is_admin` = 1', [$id]);

        if ($user === null) {
            return Response::redirect('/admin/access/users');
        }

        $me = app('auth')->user();
        $isSelf = $me !== null && $me->id === $id;

        $name = trim((string) $request->input('name', (string) $user['name']));
        $roleId = (int) $request->input('role_id', (int) $user['role_id']);
        $status = (string) $request->input('status', (string) $user['status']) === 'disabled' ? 'disabled' : 'active';
        $password = (string) $request->input('password', '');

        if ($name === '' || strlen($name) > 191) {
            app('session')->flash('error', 'A display name is required (max 191 characters).');
            return Response::redirect('/admin/access/users/' . $id);
        }

        $role = app('db')->selectOne('SELECT * FROM `roles` WHERE `id` = ?', [$roleId]);

        if ($role === null) {
            app('session')->flash('error', 'Unknown role.');
            return Response::redirect('/admin/access/users/' . $id);
        }

        if ($isSelf && $roleId !== (int) $user['role_id']) {
            app('session')->flash('error', 'You cannot change your own role.');
            return Response::redirect('/admin/access/users/' . $id);
        }

        if ($isSelf && $status !== 'active') {
            app('session')->flash('error', 'You cannot deactivate yourself.');
            return Response::redirect('/admin/access/users/' . $id);
        }

        // A limited admin may not promote a user into a role broader than their
        // own, nor change the password of a more privileged login (takeover).
        $actor = app('auth')->user();
        $gate = app('gate');
        if (!$gate->isFullAdmin($actor)) {
            $targetRoleId = (int) $user['role_id'];
            if (($role['slug'] ?? '') === 'superadmin'
                || !$gate->roleWithinActor($actor, $roleId)
                || ($password !== '' && !$gate->roleWithinActor($actor, $targetRoleId))
            ) {
                app('session')->flash('error', 'You cannot manage a user with a role broader than your own.');
                return Response::redirect('/admin/access/users/' . $id);
            }
        }

        if ($password !== '' && strlen($password) < (int) config('auth.min_password_length', 10)) {
            app('session')->flash('error', 'New password must be at least ' . config('auth.min_password_length', 10) . ' characters (leave blank to keep).');
            return Response::redirect('/admin/access/users/' . $id);
        }

        $data = [
            'name' => $name,
            'role_id' => $roleId,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($password !== '') {
            $data['password'] = hash_password($password);
        }

        app('db')->update('users', $data, '`id` = ?', [$id]);

        // A password rotation by an administrator is an incident-response
        // action: kill that user's live sessions so a stolen cookie cannot
        // outlive the reset.
        if ($password !== '') {
            app(\Modules\Authentication\Services\SessionService::class)
                ->revokeOthers((int) $id, null);
        }

        audit('authorization.user.updated', 'user', $id, ['role' => $role['slug'], 'status' => $status]);
        app('session')->flash('success', 'Admin user updated.');

        return Response::redirect('/admin/access/users');
    }
}
