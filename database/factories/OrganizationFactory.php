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
            'name' => $this->faker->company(),
            'slug' => Str::slug($this->faker->unique()->company()).'-'.$this->faker->unique()->numberBetween(100, 999),
            'primary_contact_name' => $this->faker->name(),
            'primary_contact_email' => $this->faker->safeEmail(),
            'primary_contact_phone' => $this->faker->e164PhoneNumber(),
            'business_phone' => $this->faker->e164PhoneNumber(),
            'business_email' => $this->faker->companyEmail(),
            'website' => $this->faker->url(),
            'billing_email' => $this->faker->safeEmail(),
            'tax_id' => $this->faker->bothify('VAT-#######'),
            'registration_number' => $this->faker->bothify('RC-#######'),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => 'Nigeria',
            'status' => 'active',
        ];
    }
}
