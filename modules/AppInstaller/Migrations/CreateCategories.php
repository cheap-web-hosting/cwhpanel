<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;
class CreateCategories extends Migration {
    public function up(): void {
        $this->createTable('ai_categories', function ($t) {
            $t->id();
            $t->string('name', 100);
            $t->string('slug', 100);
            $t->nullableText('description');
            $t->unsignedInteger('sort_order');
            $t->boolean('is_active');
            $t->timestamps();
            $t->unique('slug');
        });
    }
}