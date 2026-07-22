<?php

namespace Database\Factories;

use App\Models\GuestSurvey;
use App\Models\GuestSurveyInvitation;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestSurveyInvitation>
 */
class GuestSurveyInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_survey_id' => GuestSurvey::factory(),
            'reservation_id' => Reservation::factory(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addDays(30),
            'sent_at' => now(),
        ];
    }
}
