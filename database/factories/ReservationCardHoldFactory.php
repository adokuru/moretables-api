<?php

namespace Database\Factories;

use App\Models\ReservationCardHold;
use App\Models\Restaurant;
use App\Models\User;
use App\ReservationCardHoldStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReservationCardHold>
 */
class ReservationCardHoldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => null,
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'provider' => 'paystack',
            'reference' => 'rch_'.strtolower((string) Str::ulid()),
            'authorization_code' => null,
            'email' => $this->faker->safeEmail(),
            'brand' => 'visa',
            'last4' => (string) $this->faker->numberBetween(1000, 9999),
            'status' => ReservationCardHoldStatus::Pending,
            'amount' => 5000,
            'currency' => 'NGN',
            'metadata' => null,
        ];
    }

    public function authorized(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationCardHoldStatus::Authorized,
            'authorization_code' => 'AUTH_'.$this->faker->bothify('??######'),
        ]);
    }
}
