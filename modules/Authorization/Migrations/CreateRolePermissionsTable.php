<?php

declare(strict_types=1);

namespace Modules\Authorization\Migrations;

use App\Foundation\Database\Migration;

class CreateRolePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('role_permissions', function ($t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
            $t->index('role_id');
            $t->index('permission_id');
        });

        $this->db->statement(
            'ALTER TABLE `role_permissions` ADD UNIQUE KEY `role_permissions_role_permission_unique` (`role_id`, `permission_id`)'
        );
    }
}
