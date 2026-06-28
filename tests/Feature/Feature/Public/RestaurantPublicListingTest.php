<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\RestaurantStatus;

it('includes active restaurants with an active merchant subscription on the public list', function () {
    $organization = Organization::factory()->create();

    $restaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Listed Restaurant',
        'slug' => 'listed-restaurant',
        'city' => 'Lagos',
    ]);

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $restaurant->id);
});

it('excludes active restaurants without an active merchant subscription from the public list', function () {
    $organization = Organization::factory()->create();

    $paidRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Paid Restaurant',
        'slug' => 'paid-restaurant',
        'city' => 'Lagos',
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Unpaid Restaurant',
        'slug' => 'unpaid-restaurant',
        'city' => 'Lagos',
    ]);

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $paidRestaurant->id);
});

it('excludes restaurants that have not completed onboarding from the public list', function () {
    $organization = Organization::factory()->create();

    $listedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Listed Restaurant',
        'slug' => 'listed-restaurant',
        'city' => 'Lagos',
    ]);

    $unpublishedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Unpublished Restaurant',
        'slug' => 'unpublished-restaurant',
        'city' => 'Lagos',
    ]);
    $unpublishedRestaurant->update(['is_profile_published' => false]);

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $listedRestaurant->id);
});

it('excludes restaurants without any bookable times from the public list', function () {
    $organization = Organization::factory()->create();

    $listedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Listed Restaurant',
        'slug' => 'listed-restaurant',
        'city' => 'Lagos',
    ]);

    $noTimesRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'No Times Restaurant',
        'slug' => 'no-times-restaurant',
        'city' => 'Lagos',
    ]);
    $noTimesRestaurant->shifts()->delete();
    $noTimesRestaurant->availabilitySchedules()->delete();
    $noTimesRestaurant->hours()->delete();

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $listedRestaurant->id);
});

it('lists a restaurant that only has an active weekly shift configured', function () {
    $organization = Organization::factory()->create();

    $restaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Shift Restaurant',
        'slug' => 'shift-restaurant',
        'city' => 'Lagos',
    ]);
    $restaurant->hours()->delete();
    RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $restaurant->id);
});

it('excludes draft restaurants from the public list', function () {
    $organization = Organization::factory()->create();

    $listedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Listed Restaurant',
        'slug' => 'listed-restaurant',
        'city' => 'Lagos',
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Draft Restaurant',
        'slug' => 'draft-restaurant',
        'city' => 'Lagos',
        'status' => RestaurantStatus::Draft,
    ]);

    $this->getJson('/api/v1/restaurants?city=Lagos')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $listedRestaurant->id);
});
