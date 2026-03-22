<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantMedia>
 */
class RestaurantMediaFactory extends Factory
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
            'collection' => 'gallery',
            'url' => fake()->imageUrl(),
            'alt_text' => fake()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 5),
        ];
    }
}
