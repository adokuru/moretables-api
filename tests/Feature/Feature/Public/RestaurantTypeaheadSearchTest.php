<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantCuisine;
use App\Models\SavedRestaurant;
use App\Models\User;
use App\RestaurantStatus;

it('returns grouped location restaurant and cuisine suggestions', function () {
    $organization = Organization::factory()->create();

    $matchingRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'London Bistro',
        'slug' => 'london-bistro',
        'city' => 'London',
        'state' => null,
        'country' => 'United Kingdom',
        'description' => 'A modern London dining room.',
    ]);

    RestaurantCuisine::factory()->create([
        'restaurant_id' => $matchingRestaurant->id,
        'name' => 'London Grill',
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Another London Spot',
        'slug' => 'another-london-spot',
        'city' => 'London',
        'state' => null,
        'country' => 'United Kingdom',
    ]);

    $user = User::factory()->create();

    SavedRestaurant::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $matchingRestaurant->id,
    ]);

    $response = $this->withToken($user->createToken('search')->plainTextToken)
        ->getJson('/api/v1/search?q=londo');

    $response->assertOk()
        ->assertJsonPath('query', 'londo')
        ->assertJsonPath('results.locations.0.name', 'London')
        ->assertJsonPath('results.locations.0.secondary_text', 'United Kingdom')
        ->assertJsonPath('results.locations.0.city', 'London')
        ->assertJsonPath('results.locations.0.country', 'United Kingdom')
        ->assertJsonPath('results.restaurants.0.id', $matchingRestaurant->id)
        ->assertJsonPath('results.restaurants.0.name', 'London Bistro')
        ->assertJsonPath('results.restaurants.0.has_saved', true)
        ->assertJsonPath('results.cuisines.0.name', 'London Grill')
        ->assertJsonPath('results.cuisines.0.restaurant_count', 1);
});

it('returns unique locations and cuisines while excluding inactive restaurants', function () {
    $organization = Organization::factory()->create();

    $activeRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Sushi One',
        'slug' => 'sushi-one',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    RestaurantCuisine::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'name' => 'Sushi',
    ]);

    $secondActiveRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Sushi Two',
        'slug' => 'sushi-two',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    RestaurantCuisine::factory()->create([
        'restaurant_id' => $secondActiveRestaurant->id,
        'name' => 'Sushi',
    ]);

    $inactiveRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Sushi Closed',
        'slug' => 'sushi-closed',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'status' => RestaurantStatus::Suspended,
    ]);

    RestaurantCuisine::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'name' => 'Sushi',
    ]);

    $locationResponse = $this->getJson('/api/v1/search?q=lagos');

    $locationResponse->assertOk()
        ->assertJsonCount(1, 'results.locations')
        ->assertJsonCount(2, 'results.restaurants')
        ->assertJsonMissingPath('results.restaurants.2')
        ->assertJsonMissing(['name' => 'Sushi Closed']);

    $cuisineResponse = $this->getJson('/api/v1/search?q=sushi');

    $cuisineResponse->assertOk()
        ->assertJsonCount(1, 'results.cuisines')
        ->assertJsonPath('results.cuisines.0.name', 'Sushi')
        ->assertJsonPath('results.cuisines.0.restaurant_count', 2);
});

it('returns restaurant list resource fields in search results', function () {
    $restaurant = Restaurant::factory()->create([
        'name' => 'The Garden',
        'slug' => 'the-garden',
        'city' => 'Abuja',
        'state' => 'FCT',
        'country' => 'Nigeria',
    ]);

    RestaurantCuisine::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Garden Fresh',
    ]);

    $response = $this->getJson('/api/v1/search?q=garden');

    $response->assertOk()
        ->assertJsonPath('results.restaurants.0.slug', 'the-garden')
        ->assertJsonPath('results.restaurants.0.city', 'Abuja')
        ->assertJsonPath('results.restaurants.0.state', 'FCT')
        ->assertJsonPath('results.restaurants.0.country', 'Nigeria')
        ->assertJsonPath('results.restaurants.0.cuisines.0', 'Garden Fresh');
});

it('validates the search query payload', function () {
    $response = $this->getJson('/api/v1/search?limit=0&q='.str_repeat('a', 101));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['q', 'limit']);
});
