<?php

namespace Database\Factories;

use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantSpecialDay;
use App\Models\RestaurantSpecialDayShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantSpecialDayShift>
 */
class RestaurantSpecialDayShiftFactory extends Factory
{
    protected $model = RestaurantSpecialDayShift::class;

    public function definition(): array
    {
        return [
            'restaurant_special_day_id' => RestaurantSpecialDay::factory(),
            'restaurant_meal_type_id' => RestaurantAvailabilityPeriod::factory(),
            'opens_at' => '09:00',
            'closes_at' => '17:00',
        ];
    }
}
