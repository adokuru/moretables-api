<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantPolicy>
 */
class RestaurantPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'reservation_duration_minutes' => 120,
            'booking_window_days' => 30,
            'cancellation_cutoff_hours' => 24,
            'min_party_size' => 1,
            'max_party_size' => 12,
            'deposit_required' => false,
        ];
    }
}
