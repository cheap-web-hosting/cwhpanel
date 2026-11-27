<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;
class CreateApplications extends Migration {
    public function up(): void {
        $this->createTable('ai_applications', function ($t) {
            $t->id();
            $t->string('slug', 120);
            $t->string('name', 191);
            $t->string('category', 100);
            $t->string('version', 32);
            $t->nullableText('description');
            $t->string('website', 255);
            $t->string('license', 100);
            $t->string('vendor', 191);
            $t->text('manifest');            // JSON: requirements, downloads, config, admin path, etc.
            $t->boolean('is_active');
            $t->boolean('is_featured');
            $t->unsignedInteger('sort_order');
            $t->timestamps();
            $t->unique('slug');
            $t->index('category');
        });
    }
}