<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantMenuItem>
 */
class RestaurantMenuItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'section_name' => $this->faker->randomElement(['Starters', 'Mains', 'Desserts', 'Drinks']),
            'item_name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 2500, 42000),
            'currency' => 'NGN',
            'sort_order' => $this->faker->numberBetween(0, 20),
        ];
    }
}
