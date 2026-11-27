<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;
class CreateInstallations extends Migration {
    public function up(): void {
        $this->createTable('ai_installations', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('application_slug', 120);
            $t->string('app_name', 191);
            $t->string('version', 32);
            $t->string('domain', 255);
            $t->string('path', 255);           // filesystem path (docroot/subdir)
            $t->string('directory', 128);      // install subdirectory (or '')
            $t->string('db_name', 100);
            $t->string('db_user', 100);
            $t->string('db_password', 255);    // sha256 hash
            $t->string('admin_user', 100);
            $t->string('admin_url', 255);
            $t->string('site_url', 255);
            $t->string('status', 32);          // pending/running/completed/failed
            $t->nullableText('config');        // JSON of app config values
            $t->timestamps();
            $t->index('account_id');
            $t->index('application_slug');
            $t->index('status');
        });
    }
}