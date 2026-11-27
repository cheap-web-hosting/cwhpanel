<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class CreateMailingListMembersTable extends Migration {
    public function up(): void {
        $this->createTable('mailing_list_members', function ($t) {
            $t->id(); $t->unsignedBigInteger('list_id'); $t->string('email', 255); $t->string('status', 32); $t->timestamps(); $t->index('list_id');
        });
        try { $this->db->statement('ALTER TABLE `mailing_list_members` ADD UNIQUE KEY `uq_list_member` (`list_id`, `email`)'); } catch (\Throwable $e) {}
    }
}
