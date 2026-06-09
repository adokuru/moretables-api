<?php

namespace Database\Factories;

use App\Models\RewardPointTransaction;
use App\Models\RewardProgram;
use App\Models\User;
use App\RewardPointTransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardPointTransaction>
 */
class RewardPointTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $points = $this->faker->numberBetween(50, 500);

        return [
            'reward_program_id' => RewardProgram::factory(),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'type' => RewardPointTransactionType::Earn,
            'points' => $points,
            'balance_after' => $points,
            'description' => $this->faker->sentence(),
            'reference_type' => null,
            'reference_id' => null,
            'metadata' => null,
            'expires_at' => now()->addYear(),
            'points_remaining' => $points,
            'credit_value' => null,
            'credit_currency' => null,
        ];
    }

    public function earnLot(int $points = 500): static
    {
        return $this->state(fn () => [
            'type' => RewardPointTransactionType::Earn,
            'points' => $points,
            'balance_after' => $points,
            'points_remaining' => $points,
            'expires_at' => now()->addYear(),
        ]);
    }

    public function expiredEarnLot(int $points = 500): static
    {
        return $this->state(fn () => [
            'type' => RewardPointTransactionType::Earn,
            'points' => $points,
            'balance_after' => $points,
            'points_remaining' => $points,
            'expires_at' => now()->subDay(),
        ]);
    }
}
