<?php

use App\Models\RestaurantServer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('returns unassigned servers when no table_id is given, or when the given table has none', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    $unassigned = RestaurantServer::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Ada',
    ]);
    $otherServer = RestaurantServer::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Zed',
    ]);

    Sanctum::actingAs($operations);

    $service = [
        'service_starts_at' => now()->addDay()->setTime(18, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(23, 0)->utc()->toIso8601String(),
    ];
    $base = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/available-servers?'.http_build_query($service);
    $tableId = $data['table']->id;

    // No table_id at all — e.g. a waitlist/reservation guest with no table
    // yet still sees who's free.
    $this->getJson($base)
        ->assertOk()
        ->assertJsonPath('assigned_server', null)
        ->assertJsonCount(2, 'servers')
        ->assertJsonPath('servers.0.name', 'Ada');

    // A table_id for a table that has no server yet — same pool.
    $this->getJson($base.'&table_id='.$tableId)
        ->assertOk()
        ->assertJsonPath('assigned_server', null)
        ->assertJsonCount(2, 'servers');

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$tableId.'/assign-server', [
        'server_id' => $unassigned->id,
        ...$service,
    ])->assertOk();

    // Now that table has a server — only that one comes back.
    $this->getJson($base.'&table_id='.$tableId)
        ->assertOk()
        ->assertJsonPath('assigned_server.id', $unassigned->id)
        ->assertJsonCount(0, 'servers');

    // The other server, still genuinely unassigned, is unaffected.
    $this->assertDatabaseHas('restaurant_servers', ['id' => $otherServer->id, 'name' => 'Zed']);
});

it('enforces table-manage permission and validates/scopes table_id for available-servers', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $guestRelations = User::factory()->create();
    assignScopedRole($guestRelations, Role::GuestRelations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($guestRelations);

    $service = [
        'service_starts_at' => now()->addDay()->setTime(18, 0)->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->setTime(23, 0)->utc()->toIso8601String(),
    ];
    $base = '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/available-servers?'.http_build_query($service);
    $this->getJson($base)->assertForbidden();

    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($operations);

    $otherData = createBookableRestaurant();
    $this->getJson($base.'&table_id='.$otherData['table']->id)->assertNotFound();
    $this->getJson($base.'&table_id=999999')->assertUnprocessable()->assertJsonValidationErrors('table_id');
});
