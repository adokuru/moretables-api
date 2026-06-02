<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantRewardRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantRewardRule>
 */
class RestaurantRewardRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'points' => fake()->numberBetween(50, 500),
            'days' => fake()->randomElements([0, 1, 2, 3, 4, 5, 6], fake()->numberBetween(1, 3)),
            'times' => null,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<int, string>  $times
     */
    public function withTimes(array $times = ['09:00', '09:15']): static
    {
        return $this->state(fn (): array => [
            'times' => $times,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
