<?php

namespace Database\Factories;

use App\Models\RestaurantShift;
use App\Models\RestaurantShiftTableAvailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantShiftTableAvailability>
 */
class RestaurantShiftTableAvailabilityFactory extends Factory
{
    protected $model = RestaurantShiftTableAvailability::class;

    public function definition(): array
    {
        return [
            'restaurant_shift_id' => RestaurantShift::factory(),
            'dining_area_id' => null,
            'table_type' => null,
            'include_combinations' => true,
            'is_reservable' => true,
        ];
    }
}
