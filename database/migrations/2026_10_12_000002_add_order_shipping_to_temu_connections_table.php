<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Temu pays the shop for shipping an order, which goes on every
 * imported order. Temu's order API does not return it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->decimal('order_shipping', 8, 2)->default(5.50)->after('import_orders');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->dropColumn('order_shipping');
        });
    }
};
