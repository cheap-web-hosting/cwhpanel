<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;

/** Per-website log cursor (max entry epoch seen) for incremental accounting. */
class AddBandwidthCursorsTable extends Migration {
    public function up(): void {
        $this->createTable('bandwidth_cursors', function ($t) {
            $t->id(); $t->unsignedBigInteger('website_id'); $t->unsignedBigInteger('last_ts'); $t->timestamps();
            $t->unique('website_id');
        });
    }
}
