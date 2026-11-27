<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

use App\Foundation\Database\Connection;

class Gate
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function can(?User $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        // is_admin with role_id NULL (legacy / emergency rows) keeps full
        // access — identical to the old blanket bypass, explicit here.
        if ($user->roleId === null) {
            return $user->isAdmin;
        }

        // Belt-and-braces: role rows are admin-only, but a customer row
        // carrying a role must never gain admin sections through it.
        if (!$user->isAdmin) {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS `cnt`
             FROM `role_permissions` rp
             JOIN `permissions` p ON p.`id` = rp.`permission_id`
             WHERE rp.`role_id` = ? AND p.`name` = ?',
            [$user->roleId, $permission]
        );

        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /** Full admin (no role restriction). */
    public function isFullAdmin(?User $user): bool
    {
        return $user !== null && $user->isAdmin && $user->roleId === null;
    }

    /**
     * True when every permission of $roleId is held by $actor (or the actor is
     * a full admin). Used to stop a limited admin from assigning/creating a
     * role broader than their own (privilege escalation via role creation).
     */
    public function roleWithinActor(User $actor, int $roleId): bool
    {
        if ($this->isFullAdmin($actor)) {
            return true;
        }

        $actorPerms = array_flip($this->permissions($actor));
        $rows = $this->db->select(
            'SELECT p.`name` FROM `role_permissions` rp
             JOIN `permissions` p ON p.`id` = rp.`permission_id`
             WHERE rp.`role_id` = ?',
            [$roleId]
        );

        foreach ($rows as $r) {
            if (!isset($actorPerms[$r['name']])) {
                return false;
            }
        }

        return true;
    }

    /** Cache of permission-prefixes that declare at least one mutating perm. */
    private ?array $mutatingPrefixes = null;

    /**
     * A mutating request (POST/PUT/PATCH/DELETE) must not be satisfied by a
     * read-only `.view` permission alone when the module declares a mutating
     * permission under the same prefix. This is what makes custom "read-only"
     * roles actually read-only (e.g. access.roles.view no longer permits
     * POST /admin/access/roles). Falls back to the plain check when the module
     * declares no mutating permission (no regression for view-only modules).
     */
    public function canMutating(?User $user, string $permission): bool
    {
        if (!$this->can($user, $permission)) {
            return false;
        }

        // Full admins (null role) are unaffected.
        if ($user === null || $user->roleId === null) {
            return true;
        }

        if (!str_ends_with($permission, '.view')) {
            return true;
        }

        $prefix = substr($permission, 0, -5); // e.g. "accounts." (keeps the dot)
        $module = rtrim($prefix, '.');

        if (!$this->moduleHasMutatingPermission($module)) {
            return true;
        }

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS `cnt`
             FROM `role_permissions` rp
             JOIN `permissions` p ON p.`id` = rp.`permission_id`
             WHERE rp.`role_id` = ? AND p.`name` LIKE ? AND p.`name` NOT LIKE ?',
            [$user->roleId, $prefix . '%', '%.view']
        );

        return (int) ($row['cnt'] ?? 0) > 0;
    }

    private function moduleHasMutatingPermission(string $module): bool
    {
        if ($this->mutatingPrefixes === null) {
            $set = [];
            foreach ($this->db->select('SELECT `name` FROM `permissions`') as $r) {
                $n = (string) ($r['name'] ?? '');
                if ($n !== '' && !str_ends_with($n, '.view') && str_contains($n, '.')) {
                    $set[substr($n, 0, strrpos($n, '.'))] = true;
                }
            }
            $this->mutatingPrefixes = $set;
        }

        return isset($this->mutatingPrefixes[$module]);
    }

    public function authorize(?User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new ForbiddenException("This action requires the [{$permission}] permission.");
        }
    }

    public function any(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function all(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function permissions(User $user): array
    {
        if ($user->isAdmin) {
            $rows = $this->db->select('SELECT `name` FROM `permissions` ORDER BY `name`');
            return array_column($rows, 'name');
        }

        if ($user->roleId === null) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT p.`name` FROM `role_permissions` rp
             JOIN `permissions` p ON p.`id` = rp.`permission_id`
             WHERE rp.`role_id` = ? ORDER BY p.`name`',
            [$user->roleId]
        );

        return array_column($rows, 'name');
    }

    /**
     * Module URL-prefix → view permission, mirroring the can: wiring on
     * the route groups. Used for admin nav filtering. Unknown prefixes
     * return a sentinel that never passes a can() check (fail-closed:
     * unmapped pages are hidden from nav; routes remain the enforcement).
     */
    public static function adminSectionPermission(string $url): ?string
    {
        $map = [
            '/admin/accounts' => 'accounts.view',
            '/admin/packages' => 'packages.view',
            '/admin/domains' => 'domains.view',
            '/admin/websites' => 'websites.view',
            '/admin/dns' => 'dns.zones.view',
            '/admin/ssl' => 'ssl.view',
            '/admin/email' => 'email.view',
            '/admin/databases' => 'databases.view',
            '/admin/files' => 'files.view',
            '/admin/ftp' => 'ftp.view',
            '/admin/cron' => 'cron.view',
            '/admin/nodejs' => 'apps.view',
            '/admin/python' => 'apps.view',
            '/admin/runtimes' => 'apps.manage',
            '/admin/applications' => 'apps.view',
            '/admin/appinstaller' => 'appinstaller.view',
            '/admin/backups' => 'backups.view',
            '/admin/monitoring' => 'monitoring.view',
            '/admin/security' => 'security.view',
            '/admin/jobs' => 'jobs.view',
            '/admin/api' => 'api.keys.view',
            '/admin/server' => 'server.view',
            '/admin/network' => 'network.view',
            '/admin/settings/modules' => 'modules.view',
            '/admin/access/roles' => 'access.roles.view',
            '/admin/access/permissions' => 'access.permissions.view',
            '/admin/access/users' => 'access.users.view',
        ];

        $best = null;
        $bestLen = -1;

        foreach ($map as $prefix => $perm) {
            if (($url === $prefix || str_starts_with($url, $prefix . '/')) && strlen($prefix) > $bestLen) {
                $best = $perm;
                $bestLen = strlen($prefix);
            }
        }

        // Fail-closed: an unmapped prefix must never render the nav item.
        return $best ?? 'admin.denied';
    }

    /**
     * Admin nav tree filtered by the user's permissions. Children the
     * user cannot view are dropped; a parent with no visible children
     * and no own URL is dropped too. Non-admins never see the admin tree.
     */
    public function filterAdminNav(array $tree, ?User $user): array
    {
        if ($user === null || !$user->isAdmin) {
            return [];
        }

        // Full admins (is_admin + no role, or the seeded system roles with
        // blanket grants) keep the fast path — but only when they truly
        // hold every permission. Custom-role admins get filtered below.
        $out = [];

        foreach ($tree as $item) {
            $children = $item['children'] ?? null;

            if (is_array($children)) {
                $item['children'] = $this->filterAdminNav($children, $user);

                if ($item['children'] === [] && !isset($item['url'])) {
                    continue;
                }

                // A parent WITH its own URL still needs a permission.
                if (isset($item['url'])) {
                    $perm = self::adminSectionPermission((string) $item['url']);

                    if ($perm !== null && !$this->can($user, $perm)) {
                        continue;
                    }
                }

                $out[] = $item;
                continue;
            }

            if (!isset($item['url'])) {
                $out[] = $item;
                continue;
            }

            $perm = self::adminSectionPermission((string) $item['url']);

            if ($perm === null || $this->can($user, $perm)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
