<?php

use App\Models\Role;
use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;

it('marks the product tour as completed for an authenticated merchant staff user', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $merchant = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'has_completed_product_tour' => false,
    ]);

    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/tour');

    $response->assertOk()
        ->assertJsonPath('message', 'Product tour marked as completed.')
        ->assertJsonPath('user.has_completed_product_tour', true);

    $this->assertDatabaseHas('users', [
        'id' => $merchant->id,
        'has_completed_product_tour' => true,
    ]);
});

it('forbids unauthenticated requests to complete the product tour', function () {
    $this->patchJson('/api/v1/auth/staff/profile/tour')->assertUnauthorized();
});
