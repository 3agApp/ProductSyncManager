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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // A deleted parent promotes its children to the top rather than
            // taking a whole branch of the catalog down with it.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            // Slugs are unique per shop rather than per parent: SQL counts
            // NULLs in a unique index as distinct, so scoping to the parent
            // would let two roots share a name.
            $table->unique(['shop_id', 'slug']);
            $table->unique(['shop_id', 'remote_id']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->unique(['product_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');
    }
};
