<?php

namespace Database\Factories;

use App\Enums\RestaurantShiftTurnControlReleasePolicy;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantShift>
 */
class RestaurantShiftFactory extends Factory
{
    protected $model = RestaurantShift::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'restaurant_meal_type_id' => null,
            'name' => fake()->randomElement(['Breakfast', 'Lunch', 'Dinner', 'Brunch']),
            'day_of_week' => fake()->numberBetween(0, 6),
            'starts_at' => '17:00',
            'ends_at' => '22:00',
            'color' => fake()->hexColor(),
            'is_active' => true,
            'turn_control_release_policy' => RestaurantShiftTurnControlReleasePolicy::DontRelease,
            'release_hours_before' => null,
            'flow_interval_minutes' => 15,
            'flow_default_max_covers' => 3,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
