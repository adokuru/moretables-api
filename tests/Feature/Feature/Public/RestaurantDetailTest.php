<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPolicy;

it('includes grouped menu items in the public restaurant detail response', function () {
    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
    ]);

    RestaurantPolicy::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    foreach (range(0, 6) as $day) {
        RestaurantHour::factory()->create([
            'restaurant_id' => $restaurant->id,
            'day_of_week' => $day,
        ]);
    }

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Starters',
        'item_name' => 'Pepper Prawns',
        'description' => 'Grilled prawns with citrus butter.',
        'price' => 13500,
        'sort_order' => 0,
    ]);

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Starters',
        'item_name' => 'Corn Ribs',
        'description' => 'Smoky corn ribs with suya mayo.',
        'price' => 6200,
        'sort_order' => 1,
    ]);

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Mains',
        'item_name' => 'Lobster Rice',
        'description' => 'Butter lobster over fragrant rice.',
        'price' => 28500,
        'sort_order' => 2,
    ]);

    $response = $this->getJson('/api/v1/restaurants/'.$restaurant->id);

    $response->assertOk()
        ->assertJsonPath('data.menus.0.section', 'Starters')
        ->assertJsonPath('data.menus.0.items.0.name', 'Pepper Prawns')
        ->assertJsonPath('data.menus.1.section', 'Mains')
        ->assertJsonPath('data.menus.1.items.0.price', 28500);
});
