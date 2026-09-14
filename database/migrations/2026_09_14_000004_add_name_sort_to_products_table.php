<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_sort', Product::NAME_SORT_LENGTH)->default('')->after('name');
        });

        DB::table('products')->select(['id', 'name'])->chunkById(500, function ($products) {
            foreach ($products as $product) {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['name_sort' => Product::nameSortKey((string) $product->name)]);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['shop_id', 'name_sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'name_sort']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('name_sort');
        });
    }
};
