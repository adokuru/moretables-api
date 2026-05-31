<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantAvailabilityPeriod>
 */
class RestaurantAvailabilityPeriodFactory extends Factory
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
            'name' => fake()->randomElement(['Breakfast', 'Brunch', 'Lunch', 'Dinner', 'Late Night']),
            'sort_order' => 0,
        ];
    }
}
