<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantHour>
 */
class RestaurantHourFactory extends Factory
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
            'day_of_week' => $this->faker->numberBetween(0, 6),
            'opens_at' => '09:00:00',
            'closes_at' => '22:00:00',
            'is_closed' => false,
        ];
    }
}
