<?php

namespace Database\Factories;

use App\Models\GuestContact;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestContact>
 */
class GuestContactFactory extends Factory
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'notes' => fake()->sentence(),
            'is_temporary' => true,
        ];
    }
}
