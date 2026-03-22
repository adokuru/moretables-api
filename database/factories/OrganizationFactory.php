<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
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
            'business_phone' => fake()->e164PhoneNumber(),
            'business_email' => fake()->companyEmail(),
            'website' => fake()->url(),
            'billing_email' => fake()->safeEmail(),
            'tax_id' => fake()->bothify('VAT-#######'),
            'registration_number' => fake()->bothify('RC-#######'),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => 'Nigeria',
            'status' => 'active',
        ];
    }
}
