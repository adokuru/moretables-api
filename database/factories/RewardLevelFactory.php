<?php

namespace Database\Factories;

use App\Models\RewardLevel;
use App\Models\RewardProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardLevel>
 */
class RewardLevelFactory extends Factory
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
            'name' => 'Bronze',
            'slug' => fake()->unique()->slug(),
            'start_points' => 0,
            'end_points' => 999,
            'sort_order' => 0,
        ];
    }
}
