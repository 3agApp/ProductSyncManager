<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The currency belongs to the shop, next to its tax settings, and a
     * WooCommerce sync copies it from the store. Marketplaces read it from
     * there instead of each keeping their own.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('currency', 3)->default('CHF')->after('tax_rate');
        });

        // Carry over what a shop had already set up for Nalda.
        DB::table('nalda_connections')
            ->select(['shop_id', 'currency'])
            ->orderBy('id')
            ->each(fn (object $connection) => DB::table('shops')
                ->where('id', $connection->shop_id)
                ->update(['currency' => strtoupper((string) $connection->currency)]));

        Schema::table('nalda_connections', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('temu_connections', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temu_connections', function (Blueprint $table) {
            $table->string('currency', 3)->default('CHF')->after('default_behavior');
        });

        Schema::table('nalda_connections', function (Blueprint $table) {
            $table->string('currency', 3)->default('CHF')->after('country');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
