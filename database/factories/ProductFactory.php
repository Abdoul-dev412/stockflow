<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
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
            'sku' => strtoupper(fake()->unique()->bothify('???-####')),
            'name' => fake()->words(3, true),
            'category_id' => Category::factory(),
            'unit' => 'unité',
            'purchase_price' => fake()->randomFloat(2, 1, 500),
            'sale_price' => null,
            'quantity' => fake()->numberBetween(20, 200),
            'minimum_stock' => 10,
            'is_active' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }

    public function lowStock(): static
    {
        return $this->state(fn () => ['quantity' => 5, 'minimum_stock' => 10]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
