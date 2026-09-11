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
            $table->text('short_description')->nullable()->after('description');
            $table->string('gtin', 64)->nullable()->after('sku');
            $table->json('brands')->nullable();
            $table->json('categories')->nullable();
            $table->text('image_url')->nullable();
            $table->json('gallery_image_urls')->nullable();
            $table->text('permalink')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'gtin',
                'brands',
                'categories',
                'image_url',
                'gallery_image_urls',
                'permalink',
            ]);
        });
    }
};
