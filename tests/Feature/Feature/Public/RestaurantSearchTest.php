<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\SavedRestaurant;
use App\Models\User;

it('filters restaurants by coordinates using latitude and longitude', function () {
    $organization = Organization::factory()->create();

    $nearRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Near Restaurant',
        'slug' => 'near-restaurant',
        'is_featured' => true,
        'latitude' => 6.4500000,
        'longitude' => 3.4500000,
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Far Restaurant',
        'slug' => 'far-restaurant',
        'latitude' => 9.0765000,
        'longitude' => 7.3986000,
    ]);

    $response = $this->getJson('/api/v1/restaurants?latitude=6.4500000&longitude=3.4500000&radius_km=15');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $nearRestaurant->id)
        ->assertJsonPath('0.is_featured', true)
        ->assertJsonPath('0.latitude', 6.45)
        ->assertJsonPath('0.longitude', 3.45);
});

it('includes has_saved for authenticated users on restaurant listings', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $savedRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Saved Restaurant',
        'slug' => 'saved-restaurant',
        'city' => 'Lagos',
    ]);

    $unsavedRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Unsaved Restaurant',
        'slug' => 'unsaved-restaurant',
        'city' => 'Lagos',
    ]);

    SavedRestaurant::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $savedRestaurant->id,
    ]);

    $response = $this->withToken($user->createToken('restaurant-list')->plainTextToken)
        ->getJson('/api/v1/restaurants?city=Lagos');

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $savedRestaurant->id,
            'has_saved' => true,
        ])
        ->assertJsonFragment([
            'id' => $unsavedRestaurant->id,
            'has_saved' => false,
        ]);
});
