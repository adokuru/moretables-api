<?php

namespace Database\Factories;

use App\Models\Organization;
use App\RestaurantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' Grill';

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999),
            'status' => RestaurantStatus::Active,
            'email' => fake()->companyEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => 'Nigeria',
            'timezone' => 'Africa/Lagos',
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => fake()->secondaryAddress(),
            'latitude' => fake()->latitude(6.3, 6.7),
            'longitude' => fake()->longitude(3.2, 3.6),
            'description' => fake()->paragraph(),
        ];
    }
}
