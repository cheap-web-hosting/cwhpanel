<?php
declare(strict_types=1);
namespace Modules\Ftp\Migrations;
use App\Foundation\Database\Migration;
class CreateFtpAccountsTable extends Migration {
    public function up(): void {
        $this->createTable('ftp_accounts', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('username', 64); $t->string('password', 255); $t->string('home_dir', 255); $t->unsignedInteger('quota_mb'); $t->string('status', 32); $t->timestamps(); $t->unique('username'); $t->index('account_id');
        });
    }
}