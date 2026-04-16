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
        $name = $this->faker->company().' Grill';

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(100, 999),
            'status' => RestaurantStatus::Active,
            'is_featured' => false,
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => 'Nigeria',
            'timezone' => 'Africa/Lagos',
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->secondaryAddress(),
            'latitude' => $this->faker->latitude(6.3, 6.7),
            'longitude' => $this->faker->longitude(3.2, 3.6),
            'description' => $this->faker->paragraph(),
            'website' => $this->faker->url(),
            'instagram_handle' => '@'.$this->faker->userName(),
            'average_price_range' => '$$',
            'dining_style' => 'Casual Dining',
            'dress_code' => 'Casual',
            'total_seating_capacity' => 80,
            'number_of_tables' => 20,
            'menu_source' => 'link',
            'menu_link' => $this->faker->url(),
            'payment_options' => ['Paystack', 'Visa'],
            'accessibility_features' => ['Wheelchair Accessible', 'Vegetarian Options'],
        ];
    }
}
