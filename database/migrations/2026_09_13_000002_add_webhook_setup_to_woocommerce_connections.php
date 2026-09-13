<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_connections', function (Blueprint $table) {
            $table->json('webhook_setup')->nullable();
            $table->timestamp('webhooks_configured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_connections', function (Blueprint $table) {
            $table->dropColumn(['webhook_setup', 'webhooks_configured_at']);
        });
    }
};
