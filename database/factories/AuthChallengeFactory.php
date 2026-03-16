<?php

namespace Database\Factories;

use App\AuthChallengeStatus;
use App\AuthChallengeType;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuthChallenge>
 */
class AuthChallengeFactory extends Factory
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
            'type' => AuthChallengeType::GuestSignup,
            'status' => AuthChallengeStatus::Pending,
            'challenge_token' => (string) Str::uuid(),
            'code_hash' => Hash::make('123456'),
            'code_expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'max_attempts' => 5,
            'last_sent_at' => now(),
            'consumed_at' => null,
            'meta' => ['email' => fake()->safeEmail()],
        ];
    }
}
