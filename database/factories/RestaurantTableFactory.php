<?php

namespace Database\Factories;

use App\Models\DiningArea;
use App\Models\Restaurant;
use App\TableStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RestaurantTable>
 */
class RestaurantTableFactory extends Factory
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
            'dining_area_id' => DiningArea::factory(),
            'name' => 'T'.fake()->unique()->numberBetween(1, 99),
            'min_capacity' => 1,
            'max_capacity' => fake()->randomElement([2, 4, 6, 8]),
            'status' => TableStatus::Available,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
