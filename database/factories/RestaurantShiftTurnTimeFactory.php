<?php

namespace Database\Factories;

use App\Models\RestaurantShift;
use App\Models\RestaurantShiftTurnTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantShiftTurnTime>
 */
class RestaurantShiftTurnTimeFactory extends Factory
{
    protected $model = RestaurantShiftTurnTime::class;

    public function definition(): array
    {
        return [
            'restaurant_shift_id' => RestaurantShift::factory(),
            'party_size' => fake()->numberBetween(1, 4),
            'duration_minutes' => 120,
        ];
    }
}
