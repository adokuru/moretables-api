<?php

use App\Models\RestaurantServer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('assigns and unassigns a server to a table, reflected in the servers list', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    $server = RestaurantServer::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Ada',
    ]);

    Sanctum::actingAs($operations);

    $tableId = $data['table']->id;
    $service = [
        'service_starts_at' => now()->addDay()->setTime(18, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(23, 0)->utc()->toIso8601String(),
    ];
    $assignUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$tableId.'/assign-server';
    $serversUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers?'.http_build_query($service);

    $this->patchJson($assignUrl, ['server_id' => $server->id, ...$service])
        ->assertOk()
        ->assertJsonPath('message', 'Server assigned successfully.')
        ->assertJsonPath('table.assigned_server_id', $server->id);

    $this->assertDatabaseHas('restaurant_server_table_assignments', [
        'restaurant_table_id' => $tableId,
        'restaurant_server_id' => $server->id,
    ]);
    $this->assertDatabaseHas('restaurant_tables', ['id' => $tableId, 'assigned_server_id' => null]);

    $this->getJson($serversUrl)
        ->assertOk()
        ->assertJsonPath('servers.0.assigned_table_ids', [$tableId]);

    $this->patchJson($assignUrl, ['server_id' => null, ...$service])
        ->assertOk()
        ->assertJsonPath('message', 'Server unassigned successfully.')
        ->assertJsonPath('table.assigned_server_id', null);

    $this->getJson($serversUrl)
        ->assertOk()
        ->assertJsonPath('servers.0.assigned_table_ids', []);

    $this->assertDatabaseMissing('restaurant_server_table_assignments', [
        'restaurant_table_id' => $tableId,
        'restaurant_server_id' => $server->id,
    ]);
});

it('keeps server assignments isolated between dated service shifts', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($operations);

    $lunchServer = RestaurantServer::factory()->create(['restaurant_id' => $data['restaurant']->id, 'name' => 'Lunch Server']);
    $dinnerServer = RestaurantServer::factory()->create(['restaurant_id' => $data['restaurant']->id, 'name' => 'Dinner Server']);
    $lunch = [
        'service_starts_at' => now()->addDay()->setTime(11, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(15, 0)->utc()->toIso8601String(),
    ];
    $dinner = [
        'service_starts_at' => now()->addDay()->setTime(18, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(23, 0)->utc()->toIso8601String(),
    ];
    $assignUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$data['table']->id.'/assign-server';

    $this->patchJson($assignUrl, ['server_id' => $lunchServer->id, ...$lunch])->assertOk();
    $this->patchJson($assignUrl, ['server_id' => $dinnerServer->id, ...$dinner])->assertOk();

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers?'.http_build_query($lunch))
        ->assertOk()
        ->assertJsonPath('servers.1.assigned_table_ids', [$data['table']->id]);
    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers?'.http_build_query($dinner))
        ->assertOk()
        ->assertJsonPath('servers.0.assigned_table_ids', [$data['table']->id]);

    $this->assertDatabaseCount('restaurant_server_table_assignments', 2);
});

it('rejects an unknown server id and enforces table-manage permission and restaurant scoping', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $tableId = $data['table']->id;
    $assignUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$tableId.'/assign-server';
    $service = [
        'service_starts_at' => now()->addDay()->setTime(18, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(23, 0)->utc()->toIso8601String(),
    ];

    $this->patchJson($assignUrl, ['server_id' => 999999, ...$service])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('server_id');

    $guestRelations = User::factory()->create();
    assignScopedRole($guestRelations, Role::GuestRelations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($guestRelations);

    $server = RestaurantServer::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $this->patchJson($assignUrl, ['server_id' => $server->id, ...$service])->assertForbidden();

    $otherData = createBookableRestaurant();
    Sanctum::actingAs($operations);
    $foreignTableUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$otherData['table']->id.'/assign-server';
    $this->patchJson($foreignTableUrl, ['server_id' => $server->id, ...$service])->assertNotFound();

    Sanctum::actingAs($operations);
    $foreignServer = RestaurantServer::factory()->create(['restaurant_id' => $otherData['restaurant']->id]);
    $this->patchJson($assignUrl, ['server_id' => $foreignServer->id, ...$service])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('server_id');
});
