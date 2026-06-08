<?php

namespace Database\Factories;

use App\Models\RestaurantShift;
use App\Models\RestaurantShiftFlowInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantShiftFlowInterval>
 */
class RestaurantShiftFlowIntervalFactory extends Factory
{
    protected $model = RestaurantShiftFlowInterval::class;

    public function definition(): array
    {
        return [
            'restaurant_shift_id' => RestaurantShift::factory(),
            'starts_at' => '18:00',
            'max_covers' => 5,
        ];
    }
}
