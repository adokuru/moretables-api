<?php

use App\Models\Organization;
use App\Models\Restaurant;
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
