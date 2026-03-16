<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DiningArea>
 */
class DiningAreaFactory extends Factory
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
            'name' => fake()->randomElement(['Main Hall', 'Terrace', 'Private Room']),
            'description' => fake()->sentence(),
            'tags' => ['indoor'],
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 5),
        ];
    }
}
