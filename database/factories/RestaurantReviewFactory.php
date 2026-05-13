<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantReview>
 */
class RestaurantReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $foodRating = $this->faker->numberBetween(1, 5);
        $serviceRating = $this->faker->numberBetween(1, 5);
        $ambienceRating = $this->faker->numberBetween(1, 5);
        $valueRating = $this->faker->numberBetween(1, 5);

        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'rating' => round(($foodRating + $serviceRating + $ambienceRating + $valueRating) / 4, 2),
            'food_rating' => $foodRating,
            'service_rating' => $serviceRating,
            'ambience_rating' => $ambienceRating,
            'value_rating' => $valueRating,
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'visited_at' => $this->faker->dateTimeBetween('-90 days', '-1 day'),
        ];
    }
}
