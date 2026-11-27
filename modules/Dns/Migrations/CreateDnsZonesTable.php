<?php

declare(strict_types=1);

namespace Modules\Dns\Migrations;

use App\Foundation\Database\Migration;

class CreateDnsZonesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('dns_zones', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 255);
            $t->nullableString('nameserver', 191);
            $t->boolean('dnssec');
            $t->string('status', 32);
            $t->timestamps();
            $t->unique('name');
            $t->index('account_id');
        });
    }
}