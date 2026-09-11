<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
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
            'name' => fake()->unique()->company(),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
        ];
    }
}
