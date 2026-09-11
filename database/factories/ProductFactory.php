<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'name' => $this->productName(),
            'sku' => strtoupper(fake()->unique()->bothify('???-#####')),
            'description' => fake()->sentence(12),
            'price' => fake()->randomFloat(2, 5, 250),
            'stock' => fake()->numberBetween(Product::LOW_STOCK_THRESHOLD + 1, 250),
            'status' => ProductStatus::Active,
        ];
    }

    /**
     * Indicate that the product carries a brand.
     *
     * The brand is attached once the product exists, so it always lands on
     * the product's own shop. Names are drawn from a small pool, which is
     * what leaves several products sharing one brand row.
     */
    public function branded(?string $name = null): static
    {
        return $this->afterCreating(function (Product $product) use ($name) {
            $product->brand()
                ->associate(Brand::findOrCreateFor($product->shop, $name ?? $this->brandName()))
                ->save();
        });
    }

    /**
     * Indicate that the product is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Draft,
        ]);
    }

    /**
     * Indicate that the product has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Archived,
        ]);
    }

    /**
     * Indicate that the product is running low on stock.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => fake()->numberBetween(1, Product::LOW_STOCK_THRESHOLD),
        ]);
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => 0,
        ]);
    }

    /**
     * Pick one of a small pool of brand names.
     */
    protected function brandName(): string
    {
        return fake()->randomElement([
            'Acme', 'Globex', 'Initech', 'Northwind', 'Umbra Goods',
        ]);
    }

    /**
     * Generate a realistic product name.
     */
    protected function productName(): string
    {
        return implode(' ', [
            fake()->randomElement(['Classic', 'Everyday', 'Premium', 'Organic', 'Vintage', 'Compact', 'Deluxe', 'Essential', 'Handmade', 'Modern']),
            fake()->randomElement(['Cotton', 'Leather', 'Ceramic', 'Bamboo', 'Wool', 'Steel', 'Linen', 'Oak', 'Glass', 'Canvas']),
            fake()->randomElement(['Tote Bag', 'Mug', 'Notebook', 'Backpack', 'Water Bottle', 'Desk Lamp', 'Throw Blanket', 'Wallet', 'Candle', 'Planter']),
        ]);
    }
}
