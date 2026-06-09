<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\WaitlistStatus;
use App\WaitlistType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
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
            'type' => WaitlistType::Seating,
            'party_size' => 2,
            'preferred_starts_at' => $startsAt,
            'preferred_ends_at' => (clone $startsAt)->addMinutes(30),
            'notes' => $this->faker->sentence(),
            'whatsapp_updates' => false,
            'notified_at' => null,
            'expires_at' => null,
            'seated_at' => null,
            'metadata' => null,
        ];
    }

    /**
     * An OpenTable-style "Notify me" table-availability alert.
     */
    public function availabilityAlert(): static
    {
        return $this->state(function (): array {
            $windowStart = now()->addDay()->setTime(11, 0);

            return [
                'type' => WaitlistType::AvailabilityAlert,
                'preferred_starts_at' => $windowStart,
                'preferred_ends_at' => (clone $windowStart)->setTime(13, 0),
                'notes' => null,
                'whatsapp_updates' => true,
            ];
        });
    }
}
