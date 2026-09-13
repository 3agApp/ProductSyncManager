<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_connections', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
        });
        Schema::create('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('woocommerce_connections')->cascadeOnDelete();
            $table->string('delivery_id', 64);
            $table->unsignedBigInteger('product_id');
            $table->string('store_url');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'delivery_id'], 'wc_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_webhook_deliveries');
        Schema::table('woocommerce_connections', fn (Blueprint $table) => $table->dropColumn('webhook_secret'));
    }
};
