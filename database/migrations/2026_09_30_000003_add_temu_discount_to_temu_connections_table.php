<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sell on Temu below the shop price, with the shop price as the
 * recommended retail price.
 *
 * The shop sets a discount for every product and a brand can set its own.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(10)->after('currency');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->decimal('temu_discount_percent', 5, 2)->nullable()->after('temu_listing');
        });

        // The recommended retail price last accepted by Temu, so a changed
        // one is sent again.
        Schema::table('temu_products', function (Blueprint $table) {
            $table->decimal('sent_list_price', 12, 2)->nullable()->after('sent_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temu_products', function (Blueprint $table) {
            $table->dropColumn('sent_list_price');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('temu_discount_percent');
        });

        Schema::table('temu_connections', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
