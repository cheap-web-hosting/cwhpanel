<?php

declare(strict_types=1);

namespace Modules\Packages\Migrations;

use App\Foundation\Database\Migration;

class CreatePackagesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('packages', function ($t) {
            $t->id();
            $t->string('name', 191);
            $t->string('slug', 191);
            $t->nullableText('description');

            $t->unsignedInteger('disk_quota_mb');
            $t->unsignedInteger('bandwidth_mb');
            $t->unsignedInteger('max_domains');
            $t->unsignedInteger('max_subdomains');
            $t->unsignedInteger('max_databases');
            $t->unsignedInteger('max_email_accounts');
            $t->unsignedInteger('max_ftp_accounts');
            $t->unsignedInteger('max_cron_jobs');
            $t->unsignedInteger('max_nodejs_apps');
            $t->unsignedInteger('max_python_apps');
            $t->unsignedInteger('max_cpu_percent');
            $t->unsignedInteger('max_memory_mb');
            $t->unsignedInteger('max_processes');

            $t->boolean('is_active');
            $t->boolean('is_system');
            $t->timestamps();
            $t->unique('slug');
        });
    }
}
