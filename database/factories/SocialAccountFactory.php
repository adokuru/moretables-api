<?php

namespace Database\Factories;

use App\Models\User;
use App\SocialAuthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SocialAccount>
 */
class SocialAccountFactory extends Factory
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
            'provider' => fake()->randomElement([SocialAuthProvider::Google, SocialAuthProvider::Apple]),
            'provider_user_id' => fake()->uuid(),
            'provider_email' => fake()->safeEmail(),
            'last_used_at' => now(),
        ];
    }
}
