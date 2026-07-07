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
    $assignUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$tableId.'/assign-server';
    $serversUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/servers';

    $this->patchJson($assignUrl, ['server_id' => $server->id])
        ->assertOk()
        ->assertJsonPath('message', 'Server assigned successfully.')
        ->assertJsonPath('table.assigned_server_id', $server->id);

    $this->assertDatabaseHas('restaurant_tables', [
        'id' => $tableId,
        'assigned_server_id' => $server->id,
    ]);

    $this->getJson($serversUrl)
        ->assertOk()
        ->assertJsonPath('servers.0.assigned_table_ids', [$tableId]);

    $this->patchJson($assignUrl, ['server_id' => null])
        ->assertOk()
        ->assertJsonPath('message', 'Server unassigned successfully.')
        ->assertJsonPath('table.assigned_server_id', null);

    $this->getJson($serversUrl)
        ->assertOk()
        ->assertJsonPath('servers.0.assigned_table_ids', []);
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

    $this->patchJson($assignUrl, ['server_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('server_id');

    $guestRelations = User::factory()->create();
    assignScopedRole($guestRelations, Role::GuestRelations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($guestRelations);

    $server = RestaurantServer::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $this->patchJson($assignUrl, ['server_id' => $server->id])->assertForbidden();

    $otherData = createBookableRestaurant();
    Sanctum::actingAs($operations);
    $foreignTableUrl = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$otherData['table']->id.'/assign-server';
    $this->patchJson($foreignTableUrl, ['server_id' => $server->id])->assertNotFound();
});
