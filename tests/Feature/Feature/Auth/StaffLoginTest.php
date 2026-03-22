<?php

use App\Models\AuthChallenge;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

it('requires email otp verification for staff login', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $operations = User::factory()->create([
        'email' => 'operations@example.com',
        'password' => 'Secret123!',
    ]);

    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    $loginResponse = $this->postJson('/api/v1/auth/staff/login', [
        'identifier' => 'operations@example.com',
        'password' => 'Secret123!',
    ]);

    $loginResponse->assertOk()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    Notification::assertSentTo($operations, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $operations->id)->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/auth/staff/verify-2fa', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'staff-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user'])
        ->assertJsonPath('user.email', 'operations@example.com');
});
