<?php

declare(strict_types=1);

namespace Modules\Websites\Migrations;

use App\Foundation\Database\Migration;

class CreateWebsitesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('websites', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('domain_id');
            $t->string('label', 191);
            $t->string('document_root', 255);
            $t->nullableString('php_version', 16);
            $t->boolean('ssl_enabled');
            $t->nullableText('nginx_template');
            $t->text('nginx_config');
            $t->string('status', 32);
            $t->timestamps();
            $t->index('account_id');
            $t->index('domain_id');
        });
    }
}