<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Categories used to be a free text list stored on the product itself.
     * They become rows of their own, arranged in a tree, and a product is
     * linked to as many of them as the store puts it in.
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

            $table->unique(['shop_id', 'slug']);
            $table->unique(['shop_id', 'remote_id']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->unique(['product_id', 'category_id']);
        });

        $this->moveCategoriesOntoRows();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('categories')->nullable();
        });

        $this->moveCategoriesBackOntoProducts();

        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');
    }

    /**
     * Turn each product's stored category names into rows and links.
     *
     * The stored lists are flat, so everything backfilled lands at the top
     * of the tree. A later sync fills the parents in from the store.
     */
    private function moveCategoriesOntoRows(): void
    {
        $categoryIds = [];

        DB::table('products')
            ->select('id', 'shop_id', 'categories')
            ->whereNotNull('categories')
            ->orderBy('id')
            ->each(function (object $product) use (&$categoryIds) {
                foreach ($this->categoryNames($product->categories) as $name) {
                    $slug = Str::slug($name);

                    if ($slug === '') {
                        continue;
                    }

                    $key = $product->shop_id.':'.$slug;

                    $categoryIds[$key] ??= DB::table('categories')->insertGetId([
                        'shop_id' => $product->shop_id,
                        'name' => $name,
                        'slug' => $slug,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('category_product')->insertOrIgnore([
                        'product_id' => $product->id,
                        'category_id' => $categoryIds[$key],
                    ]);
                }
            });
    }

    /**
     * Put each product's categories back into the stored list.
     */
    private function moveCategoriesBackOntoProducts(): void
    {
        DB::table('category_product')
            ->join('categories', 'categories.id', '=', 'category_product.category_id')
            ->select('category_product.product_id', 'categories.name')
            ->orderBy('category_product.id')
            ->get()
            ->groupBy('product_id')
            ->each(function ($rows, int $productId) {
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['categories' => json_encode($rows->pluck('name')->all())]);
            });
    }

    /**
     * Read the usable names out of a stored category list.
     *
     * @return array<int, string>
     */
    private function categoryNames(mixed $categories): array
    {
        $names = json_decode((string) $categories, true);

        if (! is_array($names)) {
            return [];
        }

        $usable = [];

        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $usable[] = trim($name);
            }
        }

        return $usable;
    }
};
