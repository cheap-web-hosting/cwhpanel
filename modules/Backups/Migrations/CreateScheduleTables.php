<?php
declare(strict_types=1);
namespace Modules\Backups\Migrations;
use App\Foundation\Database\Migration;

/**
 * Scheduled + offsite backups: customer S3 remotes, backup schedules with
 * retention, and the per-artifact remote-copy ledger.
 */
class CreateScheduleTables extends Migration {
    public function up(): void {
        $this->createTable('backup_remotes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 64);
            $t->string('driver', 16);
            $t->string('endpoint', 255);
            $t->string('bucket', 128);
            $t->string('region', 32);
            $t->nullableString('path_prefix', 128);
            $t->string('access_key', 255);
            $t->text('secret_encrypted');
            $t->string('status', 32);
            $t->timestamps();
            $t->index('account_id');
        });
        $this->createTable('backup_schedules', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 64);
            $t->string('frequency', 16);
            $t->string('type', 32);
            $t->unsignedInteger('keep_local');
            $t->nullableBigInteger('remote_id');
            $t->unsignedInteger('keep_remote');
            $t->boolean('enabled');
            $t->unsignedInteger('last_run_at');
            $t->unsignedInteger('next_run_at');
            $t->nullableString('last_status', 32);
            $t->nullableString('last_error', 500);
            $t->timestamps();
            $t->index('account_id');
            $t->index('enabled');
        });
        $this->createTable('backup_copies', function ($t) {
            $t->id();
            $t->unsignedBigInteger('backup_id');
            $t->unsignedBigInteger('remote_id');
            $t->string('remote_path', 500);
            $t->unsignedBigInteger('size_bytes');
            $t->string('status', 32);
            $t->timestamps();
            $t->index('backup_id');
            $t->index('remote_id');
        });
    }
}
