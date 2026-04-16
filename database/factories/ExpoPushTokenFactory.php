<?php

namespace Database\Factories;

use App\Models\ExpoPushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpoPushToken>
 */
class ExpoPushTokenFactory extends Factory
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
            'expo_token' => 'ExponentPushToken['.$this->faker->unique()->regexify('[A-Za-z0-9]{32}').']',
            'device_id' => $this->faker->uuid(),
            'device_name' => $this->faker->randomElement(['iPhone 15 Pro', 'iPhone 14', 'Pixel 8', 'Samsung Galaxy S24']),
            'platform' => $this->faker->randomElement(['ios', 'android']),
            'app_version' => $this->faker->numerify('#.#.#'),
            'last_seen_at' => now(),
        ];
    }
}
