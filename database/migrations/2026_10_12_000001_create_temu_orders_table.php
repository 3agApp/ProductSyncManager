<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import Temu orders into WooCommerce, which fulfils them and owns the
 * stock.
 *
 * Only what is needed to import an order once and follow its status is
 * kept here; the buyer's address goes straight to WooCommerce.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->boolean('import_orders')->default(false)->after('sync_after_sync');
            $table->timestamp('orders_checked_at')->nullable()->after('last_synced_at');
        });

        Schema::create('temu_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('parent_order_sn', 64);
            $table->unsignedSmallInteger('temu_status');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('woocommerce_order_id')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('ship_by_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'parent_order_sn']);
            $table->index(['shop_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temu_orders');

        Schema::table('temu_connections', function (Blueprint $table) {
            $table->dropColumn(['import_orders', 'orders_checked_at']);
        });
    }
};
