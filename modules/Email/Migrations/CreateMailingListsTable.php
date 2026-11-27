<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class CreateMailingListsTable extends Migration {
    public function up(): void {
        $this->createTable('mailing_lists', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->unsignedBigInteger('domain_id'); $t->string('name', 64); $t->nullableText('description'); $t->string('posting', 16); $t->string('status', 32); $t->timestamps(); $t->index('account_id'); $t->index('domain_id');
        });
        try { $this->db->statement('ALTER TABLE `mailing_lists` ADD UNIQUE KEY `uq_list_domain_name` (`domain_id`, `name`)'); } catch (\Throwable $e) {}
    }
}
