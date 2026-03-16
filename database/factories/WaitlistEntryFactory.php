<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\User;
use App\WaitlistStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(19, 0);

        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'guest_contact_id' => null,
            'reservation_id' => null,
            'status' => WaitlistStatus::Waiting,
            'party_size' => 2,
            'preferred_starts_at' => $startsAt,
            'preferred_ends_at' => (clone $startsAt)->addMinutes(30),
            'notes' => fake()->sentence(),
            'notified_at' => null,
            'expires_at' => null,
            'seated_at' => null,
            'metadata' => null,
        ];
    }
}
