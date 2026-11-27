<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateSettingsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('settings', function ($t) {
            $t->stringPrimary('key', 191);
            $t->nullableText('value');
            $t->timestamp('updated_at', true);
        });
    }
}
