<?php

namespace Database\Factories;

use App\Models\OnboardingRequest;
use App\OnboardingRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingRequest>
 */
class OnboardingRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_name' => fake()->company().' Bistro',
            'owner_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'address' => fake()->address(),
            'notes' => fake()->sentence(),
            'status' => OnboardingRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
