<?php

namespace Database\Factories;

use App\Models\GuestCommunicationSetting;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestCommunicationSetting>
 */
class GuestCommunicationSettingFactory extends Factory
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
            'automated_messaging_enabled' => false,
            'reservation_messaging_enabled' => false,
        ];
    }
}
