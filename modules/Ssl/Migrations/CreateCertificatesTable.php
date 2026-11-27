<?php

declare(strict_types=1);

namespace Modules\Ssl\Migrations;

use App\Foundation\Database\Migration;

class CreateCertificatesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('certificates', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('domain_id');
            $t->nullableBigInteger('website_id', true);
            $t->string('domain_name', 255);
            $t->string('type', 32);
            $t->nullableString('cert_path', 255);
            $t->nullableString('key_path', 255);
            $t->timestamp('issued_at', true);
            $t->timestamp('expires_at', true);
            $t->boolean('auto_renew');
            $t->string('status', 32);
            $t->timestamps();
            $t->index('domain_id');
            $t->index('status');
        });
    }
}