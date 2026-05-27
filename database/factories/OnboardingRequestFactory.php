<?php

namespace Database\Factories;

use App\Models\OnboardingRequest;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
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
        $firstName = $this->faker->firstName();
        $lastName = $this->faker->lastName();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'owner_name' => "{$firstName} {$lastName}",
            'restaurant_name' => $this->faker->company().' Bistro',
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'job_title' => $this->faker->randomElement(OnboardingJobTitle::cases()),
            'location_count' => $this->faker->randomElement(OnboardingLocationCount::cases()),
            'contact_reason' => $this->faker->randomElement(OnboardingContactReason::cases()),
            'address' => $this->faker->address(),
            'notes' => $this->faker->sentence(),
            'status' => OnboardingRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
