<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a product carry its own title on Temu.
 *
 * Temu picks a product's category from its title, and a short store name
 * such as "Krone bestickt" gives it too little to go on. The store sync
 * owns the name, so the Temu title is a column of its own that the sync
 * never writes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('temu_name', 500)->nullable()->after('temu_listing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('temu_name');
        });
    }
};
