<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a whole brand be put on or kept off Temu and Nalda.
 *
 * The choice sits between the shop's default and each product's own: a
 * product that follows along follows its brand, and a brand that follows
 * along follows the shop.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('nalda_listing', 16)->default('default')->after('slug');
            $table->string('temu_listing', 16)->default('default')->after('nalda_listing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['nalda_listing', 'temu_listing']);
        });
    }
};
