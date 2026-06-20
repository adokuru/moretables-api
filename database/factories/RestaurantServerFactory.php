<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantServer>
 */
class RestaurantServerFactory extends Factory
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
            'name' => $this->faker->name(),
        ];
    }
}
