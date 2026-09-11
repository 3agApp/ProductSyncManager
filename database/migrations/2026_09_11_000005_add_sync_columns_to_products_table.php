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
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('remote_id')->nullable()->after('shop_id');
            $table->timestamp('last_synced_at')->nullable();

            $table->unique(['shop_id', 'remote_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'remote_id']);
            $table->dropColumn(['remote_id', 'last_synced_at']);
        });
    }
};
