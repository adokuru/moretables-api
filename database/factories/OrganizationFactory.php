<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => Str::slug(fake()->unique()->company()).'-'.fake()->unique()->numberBetween(100, 999),
            'primary_contact_name' => fake()->name(),
            'primary_contact_email' => fake()->safeEmail(),
            'primary_contact_phone' => fake()->e164PhoneNumber(),
            'status' => 'active',
        ];
    }
}
