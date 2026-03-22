<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\ReservationSource;
use App\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(18, 0);

        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'guest_contact_id' => null,
            'restaurant_table_id' => RestaurantTable::factory(),
            'canceled_by_user_id' => null,
            'reservation_reference' => fake()->unique()->bothify('MT######'),
            'source' => ReservationSource::Customer,
            'status' => ReservationStatus::Booked,
            'party_size' => 2,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->addHours(2),
            'notes' => fake()->sentence(),
            'internal_notes' => null,
            'seated_at' => null,
            'completed_at' => null,
            'canceled_at' => null,
            'metadata' => null,
        ];
    }
}
