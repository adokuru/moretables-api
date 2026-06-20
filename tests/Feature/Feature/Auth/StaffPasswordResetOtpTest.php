<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('sends an otp and resets a staff password', function (): void {
    $data = createBookableRestaurant();
    $user = User::factory()->create([
        'email' => 'otp-reset@example.com',
        'password' => Hash::make('OldSecret123!'),
    ]);
    assignScopedRole($user, Role::Operations, $data['organization'], $data['restaurant']);

    $initiate = $this->postJson('/api/v1/auth/password/forgot-otp', [
        'email' => 'otp-reset@example.com',
    ]);

    $initiate->assertOk()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    $challengeToken = $initiate->json('challenge_token');

    $reset = $this->postJson('/api/v1/auth/password/reset-otp', [
        'challenge_token' => $challengeToken,
        'code' => '1234',
        'password' => 'NewSecret456!',
        'password_confirmation' => 'NewSecret456!',
    ]);

    $reset->assertOk()
        ->assertJsonPath('message', 'Your password has been reset successfully.');

    expect(Hash::check('NewSecret456!', $user->refresh()->password))->toBeTrue();
});

it('rejects an incorrect otp code', function (): void {
    $data = createBookableRestaurant();
    $user = User::factory()->create(['email' => 'otp-bad-code@example.com']);
    assignScopedRole($user, Role::Operations, $data['organization'], $data['restaurant']);

    $challengeToken = $this->postJson('/api/v1/auth/password/forgot-otp', [
        'email' => 'otp-bad-code@example.com',
    ])->json('challenge_token');

    $this->postJson('/api/v1/auth/password/reset-otp', [
        'challenge_token' => $challengeToken,
        'code' => '0000',
        'password' => 'NewSecret456!',
        'password_confirmation' => 'NewSecret456!',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'The verification code is invalid.');
});

it('does not issue an otp for non-staff accounts', function (): void {
    $user = User::factory()->create(['email' => 'customer-otp@example.com']);
    assignScopedRole($user, Role::Customer);

    $this->postJson('/api/v1/auth/password/forgot-otp', [
        'email' => 'customer-otp@example.com',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'We could not find a staff account with that email address.');
});
