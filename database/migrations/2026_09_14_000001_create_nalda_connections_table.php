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
        Schema::create('nalda_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sftp_username')->nullable();
            $table->text('sftp_password')->nullable();
            $table->string('file_name', 100)->default('products.csv');
            $table->string('default_behavior')->default('include_all');
            $table->string('country', 2)->default('CH');
            $table->string('currency', 3)->default('CHF');
            $table->string('condition', 32)->default('new');
            // The tax rate itself comes from the shop's tax settings; this only
            // says what to do with prices entered without VAT.
            $table->string('price_mode', 16)->default('as_is');
            $table->unsignedSmallInteger('delivery_time_days')->default(3);
            $table->unsignedSmallInteger('return_days')->default(14);
            $table->boolean('upload_after_sync')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_exported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nalda_connections');
    }
};
