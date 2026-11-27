<?php

declare(strict_types=1);

namespace Modules\Backups\Migrations;

use App\Foundation\Database\Migration;

/**
 * Fleet backup plan: one admin-level schedule that backs up EVERY active
 * account with a chosen component set (files / databases / mail / DNS zones)
 * at a chosen frequency. Per-account `backup_schedules` stay untouched.
 */
class CreateBackupPlans extends Migration
{
    public function up(): void
    {
        try {
            $this->createTable('backup_plans', function ($t) {
                $t->id();
                $t->boolean('enabled');
                $t->string('frequency', 16);
                $t->string('components', 128);   // csv: files,databases,mail,dns
                $t->unsignedInteger('keep_local');
                $t->unsignedInteger('keep_remote');
                $t->nullableBigInteger('remote_id');
                $t->unsignedInteger('last_run_at');
                $t->unsignedInteger('next_run_at');
                $t->nullableString('last_status', 32);
                $t->nullableString('last_error', 500);
                $t->timestamps();
                $t->index('enabled');
            });
        } catch (\Throwable $e) {
            // already exists
        }
    }
}