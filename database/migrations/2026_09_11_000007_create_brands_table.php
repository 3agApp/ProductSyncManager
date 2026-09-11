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
     * Brands used to be a free text list stored on the product itself. They
     * become rows of their own so a shop has one spelling per brand, and a
     * product points at a single one of them.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('shop_id')->constrained()->nullOnDelete();
        });

        $this->moveBrandsOntoRows();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('brands');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('brands')->nullable();
        });

        $this->moveBrandsBackOntoProducts();

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });

        Schema::dropIfExists('brands');
    }

    /**
     * Turn each product's stored brand names into brand rows.
     *
     * Only the first name survives, since a product now has room for one
     * brand. Names that differ only in spacing or case collapse onto the
     * same row, which is the point of giving them a table.
     */
    private function moveBrandsOntoRows(): void
    {
        $brandIds = [];

        DB::table('products')
            ->select('id', 'shop_id', 'brands')
            ->whereNotNull('brands')
            ->orderBy('id')
            ->each(function (object $product) use (&$brandIds) {
                $name = $this->firstBrandName($product->brands);
                $slug = Str::slug((string) $name);

                if ($slug === '') {
                    return;
                }

                $key = $product->shop_id.':'.$slug;

                $brandIds[$key] ??= DB::table('brands')->insertGetId([
                    'shop_id' => $product->shop_id,
                    'name' => $name,
                    'slug' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['brand_id' => $brandIds[$key]]);
            });
    }

    /**
     * Put each product's brand back into the stored list.
     */
    private function moveBrandsBackOntoProducts(): void
    {
        DB::table('brands')->orderBy('id')->each(function (object $brand) {
            DB::table('products')
                ->where('brand_id', $brand->id)
                ->update(['brands' => json_encode([$brand->name])]);
        });
    }

    /**
     * Read the first usable name out of a stored brand list.
     */
    private function firstBrandName(mixed $brands): ?string
    {
        $names = json_decode((string) $brands, true);

        if (! is_array($names)) {
            return null;
        }

        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }
};
