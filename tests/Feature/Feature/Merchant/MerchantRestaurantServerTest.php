<?php

use App\Models\RestaurantServer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('lists restaurant servers and adds one using only a name', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);
    RestaurantServer::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Zainab',
    ]);
    $otherRestaurant = createBookableRestaurant();
    RestaurantServer::factory()->create([
        'restaurant_id' => $otherRestaurant['restaurant']->id,
        'name' => 'Hidden Server',
    ]);

    Sanctum::actingAs($operations);

    $url = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers';

    $this->postJson($url, ['name' => '  Ada  '])
        ->assertCreated()
        ->assertJsonPath('message', 'Server added successfully.')
        ->assertJsonPath('server.name', 'Ada')
        ->assertJsonMissingPath('server.email')
        ->assertJsonMissingPath('server.user');

    $this->assertDatabaseHas('restaurant_servers', [
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Ada',
    ]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonCount(2, 'servers')
        ->assertJsonPath('servers.0.name', 'Ada')
        ->assertJsonPath('servers.1.name', 'Zainab')
        ->assertJsonMissing(['name' => 'Hidden Server']);
});

it('accepts an optional color when creating a server and validates its format', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $url = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers';

    $this->postJson($url, ['name' => 'Ada', 'color' => '#A52700'])
        ->assertCreated()
        ->assertJsonPath('server.color', '#A52700');

    $this->assertDatabaseHas('restaurant_servers', [
        'name' => 'Ada',
        'color' => '#A52700',
    ]);

    $this->postJson($url, ['name' => 'Zed', 'color' => 'not-a-color'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('color');

    $this->postJson($url, ['name' => 'Bello'])
        ->assertCreated()
        ->assertJsonPath('server.color', null);
});

it('validates server names and restricts creation to front of house managers', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $url = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers';

    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($url, ['name' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($url, ['name' => 'Ada'])->assertCreated();
    $this->postJson($url, ['name' => 'Ada'])->assertUnprocessable()->assertJsonValidationErrors('name');

    $guestRelations = User::factory()->create();
    assignScopedRole($guestRelations, Role::GuestRelations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($guestRelations);

    $this->getJson($url)->assertOk();
    $this->postJson($url, ['name' => 'Blocked'])->assertForbidden();
});
