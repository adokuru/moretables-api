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
        return [
            'reward_program_id' => RewardProgram::factory(),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'type' => RewardPointTransactionType::Adjustment,
            'points' => 100,
            'balance_after' => 100,
            'description' => $this->faker->sentence(),
            'reference_type' => null,
            'reference_id' => null,
            'metadata' => null,
        ];
    }
}
