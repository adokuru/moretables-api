<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('fills in a default message for a bare abort(403) that was raised with no custom message', function () {
    $data = createBookableRestaurant();
    // No restaurant-access role at all — trips EnsureMerchantAccess's bare
    // abort_unless($cond, 403), the same pattern used by nearly every
    // hasRestaurantPermission() check throughout the app.
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff');

    $response->assertForbidden();
    expect($response->json('message'))->toBe("You don't have permission to perform this action.");
});

it('leaves a custom abort message untouched', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $data['organization']);
    Sanctum::actingAs($owner);

    // MerchantRestaurantStaffController::update aborts with an explicit message
    // when a user tries to change their own status — must not be overwritten.
    $response = $this->patchJson(
        '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff/'.$owner->id,
        ['status' => 'suspended'],
    );

    $response->assertForbidden();
    expect($response->json('message'))->toBe('You cannot change your own account status.');
});
