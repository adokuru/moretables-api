<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRestaurantList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRestaurantList>
 */
class UserRestaurantListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Date Night', 'Weekend Picks', 'Saved for Later', 'Birthday Ideas']),
            'description' => fake()->sentence(),
            'is_private' => fake()->boolean(25),
        ];
    }
}
