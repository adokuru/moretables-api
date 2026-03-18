<?php

use App\Models\AuthChallenge;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

it('requires email otp verification for admin login via admin routes', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => 'business-admin@example.com',
        'password' => 'Secret123!',
    ]);

    assignScopedRole($admin, Role::BusinessAdmin);

    $loginResponse = $this->postJson('/api/v1/admin/auth/login', [
        'identifier' => 'business-admin@example.com',
        'password' => 'Secret123!',
    ]);

    $loginResponse->assertOk()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    Notification::assertSentTo($admin, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $admin->id)->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/admin/auth/verify-2fa', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'admin-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user'])
        ->assertJsonPath('user.email', 'business-admin@example.com');
});

it('rejects admin users on the staff login endpoint', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => 'dev-admin@example.com',
        'password' => 'Secret123!',
    ]);

    assignScopedRole($admin, Role::DevAdmin);

    $response = $this->postJson('/api/v1/auth/staff/login', [
        'identifier' => 'dev-admin@example.com',
        'password' => 'Secret123!',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.identifier.0', 'Use the admin login endpoint for this account.');
});
