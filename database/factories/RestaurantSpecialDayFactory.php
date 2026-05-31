<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantSpecialDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantSpecialDay>
 */
class RestaurantSpecialDayFactory extends Factory
{
    protected $model = RestaurantSpecialDay::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->randomElement([
                'Thanksgiving Day', 'Christmas Eve', "New Year's Day", "Valentine's Day",
            ]),
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'is_closed' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_closed' => true,
        ]);
    }
}
