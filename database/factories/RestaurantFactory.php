<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Restaurant;
use App\RestaurantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
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
            'website' => fake()->url(),
            'instagram_handle' => '@'.fake()->userName(),
            'average_price_range' => '$$',
            'dining_style' => 'Casual Dining',
            'dress_code' => 'Casual',
            'total_seating_capacity' => 80,
            'number_of_tables' => 20,
            'menu_source' => 'link',
            'menu_link' => fake()->url(),
            'payment_options' => ['Paystack', 'Visa'],
            'accessibility_features' => ['Wheelchair Accessible', 'Vegetarian Options'],
        ];
    }
}
