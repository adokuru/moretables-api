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
            'url' => $this->faker->imageUrl(),
            'alt_text' => $this->faker->sentence(4),
            'sort_order' => $this->faker->numberBetween(0, 5),
        ];
    }
}
