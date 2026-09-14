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
        Schema::create('temu_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('app_key')->nullable();
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            // Nothing is listed until a product or the default says so, so a
            // first connection can be tried out on a single product.
            $table->string('default_behavior')->default('exclude_all');
            $table->string('currency', 3)->default('CHF');
            $table->boolean('sync_after_sync')->default(true);
            $table->unsignedBigInteger('mall_id')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('temu_listing', 16)->default('default')->after('nalda_listing');
        });

        // What this app has sent to Temu for each product. The row outlives
        // a deleted product, so the next sync can still take it off sale.
        Schema::create('temu_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('external_goods_id', 128);
            $table->string('external_sku_id', 128);
            $table->unsignedBigInteger('goods_id')->nullable();
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('temu_sub_status')->nullable();
            $table->text('message')->nullable();
            $table->decimal('sent_price', 12, 2)->nullable();
            $table->integer('sent_stock')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('on_sale')->default(true);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'external_goods_id']);
            $table->index(['shop_id', 'status']);
        });

        Schema::create('temu_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // Set only while the run is queued or running, so the unique
            // index allows one active run per shop.
            $table->foreignId('active_shop_id')->nullable()->unique()->constrained('shops')->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger');
            $table->string('status');
            $table->unsignedInteger('created_products')->default(0);
            $table->unsignedInteger('updated_products')->default(0);
            $table->unsignedInteger('removed_products')->default(0);
            $table->unsignedInteger('failed_products')->default(0);
            $table->unsignedInteger('skipped_products')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temu_sync_runs');
        Schema::dropIfExists('temu_products');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('temu_listing');
        });

        Schema::dropIfExists('temu_connections');
    }
};
