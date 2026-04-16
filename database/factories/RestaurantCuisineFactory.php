<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantCuisine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantCuisine>
 */
class RestaurantCuisineFactory extends Factory
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
            'name' => $this->faker->randomElement(['Nigerian', 'African', 'Seafood', 'Steakhouse', 'Italian']),
        ];
    }
}
