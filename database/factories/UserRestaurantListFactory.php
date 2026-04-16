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
            'name' => $this->faker->randomElement(['Date Night', 'Weekend Picks', 'Saved for Later', 'Birthday Ideas']),
            'description' => $this->faker->sentence(),
            'is_private' => $this->faker->boolean(25),
        ];
    }
}
