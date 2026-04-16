<?php

namespace Database\Factories;

use App\Models\DiningArea;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiningArea>
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
            'name' => $this->faker->randomElement(['Main Hall', 'Terrace', 'Private Room']),
            'description' => $this->faker->sentence(),
            'tags' => ['indoor'],
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 5),
        ];
    }
}
