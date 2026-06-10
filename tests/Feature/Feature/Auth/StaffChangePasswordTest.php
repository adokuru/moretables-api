<?php

use App\Models\Role;
use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;

it('changes password for an authenticated merchant staff user', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $merchant = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'password' => bcrypt('OldPassword1!'),
    ]);

    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/password', [
        'current_password' => 'OldPassword1!',
        'password' => 'NewPassword2@',
        'password_confirmation' => 'NewPassword2@',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Password changed successfully.');
});

it('rejects incorrect current password when changing merchant staff password', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $merchant = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'password' => bcrypt('OldPassword1!'),
    ]);

    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/password', [
        'current_password' => 'WrongPassword!',
        'password' => 'NewPassword2@',
        'password_confirmation' => 'NewPassword2@',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

it('forbids customers from changing password via the merchant staff endpoint', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
        'password' => bcrypt('OldPassword1!'),
    ]);

    $token = $user->createToken('customer-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/password', [
        'current_password' => 'OldPassword1!',
        'password' => 'NewPassword2@',
        'password_confirmation' => 'NewPassword2@',
    ]);

    $response->assertForbidden();
});

it('forbids admin users from changing password via the merchant staff endpoint', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'password' => bcrypt('OldPassword1!'),
    ]);

    assignScopedRole($admin, Role::SuperAdmin);

    $token = $admin->createToken('admin-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/password', [
        'current_password' => 'OldPassword1!',
        'password' => 'NewPassword2@',
        'password_confirmation' => 'NewPassword2@',
    ]);

    $response->assertForbidden();
});
