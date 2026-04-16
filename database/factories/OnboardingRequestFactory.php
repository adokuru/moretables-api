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
            'restaurant_name' => $this->faker->company().' Bistro',
            'owner_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'address' => $this->faker->address(),
            'notes' => $this->faker->sentence(),
            'status' => OnboardingRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
