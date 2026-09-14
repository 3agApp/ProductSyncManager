<?php

namespace Database\Factories;

use App\Enums\TemuProductStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TemuProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemuProduct>
 */
class TemuProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'product_id' => null,
            'external_goods_id' => 'psm-'.fake()->unique()->numberBetween(1, 999999),
            'external_sku_id' => fake()->bothify('SKU-####'),
            'goods_id' => fake()->numberBetween(600000000000000, 699999999999999),
            'sku_id' => fake()->numberBetween(50000000000000, 59999999999999),
            'status' => TemuProductStatus::Active,
            'temu_sub_status' => 2001,
            'sent_price' => '19.90',
            'sent_stock' => 5,
            'on_sale' => true,
            'submitted_at' => now()->subDay(),
            'checked_at' => now()->subHour(),
        ];
    }

    /**
     * Link the record to a product, using its identifiers.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'external_goods_id' => TemuProduct::externalGoodsId($product),
            'external_sku_id' => $product->sku,
        ]);
    }
}
