<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
        ];
    }

    /**
     * Indicate that the category sits under another one.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'shop_id' => $parent->shop_id,
            'parent_id' => $parent->id,
        ]);
    }
}
