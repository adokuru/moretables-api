<?php

use App\Models\AuditLog;
use App\Models\AuthChallenge;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

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

    $verifyResponse = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ])->postJson('/api/v1/admin/auth/verify-2fa', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'admin-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user'])
        ->assertJsonPath('user.email', 'business-admin@example.com');

    $loginLog = AuditLog::query()->where('action', 'admin.auth.login')->latest('id')->first();

    expect($loginLog)->not->toBeNull()
        ->and($loginLog->actor_user_id)->toBe($admin->id)
        ->and($loginLog->description)->toBe('Signed in to the admin dashboard.')
        ->and($loginLog->ip_address)->not->toBeEmpty()
        ->and($loginLog->new_values['email'] ?? null)->toBe('business-admin@example.com')
        ->and($loginLog->new_values['device_name'] ?? null)->toBe('admin-device')
        ->and($loginLog->new_values['platform'] ?? null)->toBe('Windows')
        ->and($loginLog->new_values['browser'] ?? null)->toBe('Chrome')
        ->and($loginLog->new_values['outcome'] ?? null)->toBe('success');
});

it('records failed admin login attempts in the audit log', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => 'fail-admin@example.com',
        'password' => 'Secret123!',
    ]);
    assignScopedRole($admin, Role::BusinessAdmin);

    $this->postJson('/api/v1/admin/auth/login', [
        'identifier' => 'fail-admin@example.com',
        'password' => 'WrongPassword!',
    ])->assertUnprocessable();

    $failedLog = AuditLog::query()->where('action', 'admin.auth.login_failed')->latest('id')->first();

    expect($failedLog)->not->toBeNull()
        ->and($failedLog->actor_user_id)->toBe($admin->id)
        ->and($failedLog->new_values['reason'] ?? null)->toBe('invalid_credentials')
        ->and($failedLog->new_values['identifier'] ?? null)->toBe('fail-admin@example.com');
});

it('records admin logout in the audit log', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => 'logout-admin@example.com',
        'password' => 'Secret123!',
    ]);
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/auth/logout')->assertOk();

    $logoutLog = AuditLog::query()->where('action', 'admin.auth.logout')->latest('id')->first();

    expect($logoutLog)->not->toBeNull()
        ->and($logoutLog->actor_user_id)->toBe($admin->id)
        ->and($logoutLog->new_values['outcome'] ?? null)->toBe('logout');
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
