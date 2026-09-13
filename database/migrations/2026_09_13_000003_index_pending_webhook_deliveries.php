<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The batched webhook job looks up a connection's unprocessed deliveries
        // on every pass, and the table keeps every delivery ever received.
        Schema::table('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->index(['connection_id', 'processed_at', 'product_id'], 'wc_delivery_pending');
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('wc_delivery_pending');
        });
    }
};
