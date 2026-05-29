<?php

namespace Database\Factories;

use App\Models\DiningArea;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\TableShape;
use App\TableStatus;
use App\TableType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantTable>
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
            'name' => 'T'.$this->faker->unique()->numberBetween(1, 99),
            'min_capacity' => RestaurantTable::DEFAULT_MIN_CAPACITY,
            'max_capacity' => RestaurantTable::DEFAULT_MAX_CAPACITY,
            'table_type' => TableType::Regular,
            'shape' => $this->faker->randomElement([TableShape::Round, TableShape::Square, TableShape::Rectangle]),
            'status' => TableStatus::Available,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 20),
            'x_position' => $this->faker->numberBetween(0, 800),
            'y_position' => $this->faker->numberBetween(0, 600),
            'width' => $this->faker->randomElement([64, 96, 128, 192]),
            'height' => $this->faker->randomElement([64, 96, 128]),
            'rotation' => 0,
            'color' => '#d9d9d9',
        ];
    }
}
