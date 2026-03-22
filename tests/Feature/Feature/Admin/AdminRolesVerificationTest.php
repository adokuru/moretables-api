<?php

use App\Models\AuthChallenge;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('seeds all required admin roles with their expected permissions', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $businessAdmin = Role::query()->where('name', Role::BusinessAdmin)->first();
    $devAdmin = Role::query()->where('name', Role::DevAdmin)->first();
    $superAdmin = Role::query()->where('name', Role::SuperAdmin)->first();

    expect($businessAdmin)->not->toBeNull();
    expect($devAdmin)->not->toBeNull();
    expect($superAdmin)->not->toBeNull();

    expect($businessAdmin?->permissions()->pluck('name')->sort()->values()->all())->toBe([
        'audit_logs.view',
        'docs.view',
        'organizations.manage',
        'restaurants.manage',
        'restaurants.view',
        'roles.manage',
    ]);

    expect($devAdmin?->permissions()->pluck('name')->sort()->values()->all())->toBe([
        'audit_logs.view',
        'docs.view',
        'organizations.manage',
        'roles.manage',
    ]);

    expect($superAdmin?->permissions()->pluck('name')->sort()->values()->all())->toBe([
        'audit_logs.view',
        'docs.view',
        'organizations.manage',
        'reservations.manage',
        'reservations.view',
        'restaurants.manage',
        'restaurants.view',
        'roles.manage',
        'staff.manage',
        'tables.manage',
        'waitlist.manage',
    ]);
});

it('allows each admin role to authenticate through the admin login flow', function (string $roleName, string $email) {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => $email,
        'password' => 'Secret123!',
    ]);

    assignScopedRole($admin, $roleName);

    $loginResponse = $this->postJson('/api/v1/admin/auth/login', [
        'identifier' => $email,
        'password' => 'Secret123!',
    ]);

    $loginResponse->assertOk()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    Notification::assertSentTo($admin, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $admin->id)->latest()->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/admin/auth/verify-2fa', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'admin-verification-test',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('user.email', $email);
})->with([
    'business admin' => [Role::BusinessAdmin, 'business-admin-verify@example.com'],
    'dev admin' => [Role::DevAdmin, 'dev-admin-verify@example.com'],
    'super admin' => [Role::SuperAdmin, 'super-admin-verify@example.com'],
]);

it('allows each admin role to use the shared admin reward-program route', function (string $roleName, string $email) {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'email' => $email,
    ]);

    assignScopedRole($admin, $roleName);

    expect($admin->requiresAdminLogin())->toBeTrue();
    expect($admin->requiresStaffLogin())->toBeFalse();

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/reward-program');

    $response->assertOk()
        ->assertJsonPath('reward_program.period_type', 'lifetime')
        ->assertJsonPath('reward_program.levels.0.slug', 'bronze')
        ->assertJsonPath('reward_program.levels.3.slug', 'platinum');
})->with([
    'business admin reward access' => [Role::BusinessAdmin, 'business-admin-access@example.com'],
    'dev admin reward access' => [Role::DevAdmin, 'dev-admin-access@example.com'],
    'super admin reward access' => [Role::SuperAdmin, 'super-admin-access@example.com'],
]);
