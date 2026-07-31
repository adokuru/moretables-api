<?php

namespace Database\Factories;

use App\Models\GuestSurvey;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestSurvey>
 */
class GuestSurveyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => 'restaurant',
            'restaurant_id' => Restaurant::factory(),
            'version' => 1,
            'publication_sequence' => null,
            'title' => 'Post Dining Questions',
            'description' => 'Tell us about your visit.',
            'logo_url' => null,
            'status' => 'draft',
            'questions' => [
                ['id' => 'food', 'type' => 'rating', 'prompt' => 'How would you rate your meal?', 'required' => true, 'options' => []],
                ['id' => 'comments', 'type' => 'long_text', 'prompt' => 'Is there anything else we could improve?', 'required' => false, 'options' => []],
            ],
            'send_delay_minutes' => 120,
            'channels' => ['push', 'email'],
            'published_at' => null,
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => 'platform',
            'restaurant_id' => null,
        ]);
    }
}
