<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;
class CreateInstallLogs extends Migration {
    public function up(): void {
        $this->createTable('ai_install_logs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('installation_id');
            $t->string('level', 16);
            $t->nullableText('message');
            $t->timestamp('created_at');
            $t->index('installation_id');
        });
    }
}