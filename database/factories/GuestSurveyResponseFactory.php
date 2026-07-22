<?php

namespace Database\Factories;

use App\Models\GuestSurveyInvitation;
use App\Models\GuestSurveyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestSurveyResponse>
 */
class GuestSurveyResponseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_survey_invitation_id' => GuestSurveyInvitation::factory(),
            'answers' => [['question_id' => 'food', 'value' => 5]],
            'submitted_at' => now(),
        ];
    }
}
