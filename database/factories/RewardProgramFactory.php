<?php

namespace Database\Factories;

use App\Models\RewardProgram;
use App\RewardProgramPeriodType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardProgram>
 */
class RewardProgramFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'MoreTables Loyalty Rewards',
            'slug' => $this->faker->unique()->slug(),
            'description' => $this->faker->sentence(),
            'period_type' => RewardProgramPeriodType::Lifetime,
            'period_value' => null,
            'resets_points' => false,
            'tier_locked_until_period_end' => false,
            'is_active' => true,
        ];
    }
}
