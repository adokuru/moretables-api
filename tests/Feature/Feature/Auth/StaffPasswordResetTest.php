<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('returns a clear success message when password reset succeeds', function (): void {
    $data = createBookableRestaurant();
    $user = User::factory()->create([
        'email' => 'staff-reset@example.com',
        'password' => Hash::make('OldSecret123!'),
    ]);

    assignScopedRole($user, Role::Operations, $data['organization'], $data['restaurant']);

    $plainToken = Password::broker()->createToken($user);

    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'staff-reset@example.com',
        'password' => 'NewSecret456!',
        'password_confirmation' => 'NewSecret456!',
        'token' => $plainToken,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Your password has been reset successfully.');

    $user->refresh();

    expect(Hash::check('NewSecret456!', $user->password))->toBeTrue();
});

it('returns a clear error message when the reset token is invalid', function (): void {
    $data = createBookableRestaurant();
    $user = User::factory()->create([
        'email' => 'staff-bad-token@example.com',
        'password' => Hash::make('OldSecret123!'),
    ]);

    assignScopedRole($user, Role::Operations, $data['organization'], $data['restaurant']);

    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'staff-bad-token@example.com',
        'password' => 'NewSecret456!',
        'password_confirmation' => 'NewSecret456!',
        'token' => 'not-a-valid-token',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This password reset link is invalid or has expired. Please request a new link.');
});

it('rejects password reset for accounts that do not require staff login', function (): void {
    $user = User::factory()->create([
        'email' => 'customer-only@example.com',
        'password' => Hash::make('Secret123!'),
    ]);

    assignScopedRole($user, Role::Customer);

    $plainToken = Password::broker()->createToken($user);

    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'customer-only@example.com',
        'password' => 'NewSecret456!',
        'password_confirmation' => 'NewSecret456!',
        'token' => $plainToken,
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Password reset is only available for staff and admin accounts.');
});
