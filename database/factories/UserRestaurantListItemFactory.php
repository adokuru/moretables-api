<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\UserRestaurantList;
use App\Models\UserRestaurantListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRestaurantListItem>
 */
class UserRestaurantListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_restaurant_list_id' => UserRestaurantList::factory(),
            'restaurant_id' => Restaurant::factory(),
            'sort_order' => fake()->numberBetween(0, 12),
        ];
    }
}
