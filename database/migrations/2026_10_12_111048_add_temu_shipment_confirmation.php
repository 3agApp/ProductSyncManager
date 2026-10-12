<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->boolean('confirm_shipments')->default(false)->after('order_shipping');
            $table->unsignedBigInteger('carrier_id')->nullable()->after('confirm_shipments');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->timestamp('shipment_confirmed_at')->nullable()->after('tracking_code');
            $table->text('shipment_message')->nullable()->after('shipment_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn(['shipment_confirmed_at', 'shipment_message']);
        });

        Schema::table('temu_connections', function (Blueprint $table) {
            $table->dropColumn(['confirm_shipments', 'carrier_id']);
        });
    }
};
