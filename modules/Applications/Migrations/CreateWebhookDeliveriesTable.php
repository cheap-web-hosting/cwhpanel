<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;

/**
 * Webhook delivery dedupe (replay protection): records each forge delivery id
 * so a captured, still-valid signed request cannot be replayed to trigger
 * repeated deployments. Bounded by a periodic prune in the receiver.
 */
class CreateWebhookDeliveriesTable extends Migration {
    public function up(): void {
        $this->createTable('webhook_deliveries', function ($t) {
            $t->id();
            $t->unsignedBigInteger('app_id');
            $t->string('delivery_id', 191);
            $t->timestamp('created_at', true);
            // Forge delivery ids are UUIDs (globally unique).
            $t->unique('delivery_id');
            $t->index('app_id');
            $t->index('created_at');
        });
    }
}
