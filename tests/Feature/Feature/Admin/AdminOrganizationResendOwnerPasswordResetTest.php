<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('resends the password reset link to the organization owner', function () {
    Notification::fake();

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/admin/organizations/{$organization->id}/resend-owner-password-reset",
    );

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Password reset link resent to organization owner.')
        ->assertJsonPath('recipients.0.email', $owner->email)
        ->assertJsonPath('recipients.0.status', Password::RESET_LINK_SENT);

    Notification::assertSentTo($owner, ResetPassword::class);

    $this->assertDatabaseHas('password_reset_tokens', [
        'email' => $owner->email,
    ]);
});

it('surfaces throttled responses without erroring', function () {
    Notification::fake();

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    Password::shouldReceive('sendResetLink')
        ->once()
        ->with(['email' => $owner->email])
        ->andReturn(Password::RESET_THROTTLED);

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/admin/organizations/{$organization->id}/resend-owner-password-reset",
    );

    $response
        ->assertOk()
        ->assertJsonPath('message', 'No password reset link was sent. See recipients for details.')
        ->assertJsonPath('recipients.0.email', $owner->email)
        ->assertJsonPath('recipients.0.status', Password::RESET_THROTTLED);
});

it('returns 404 when the organization has no owner assigned', function () {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/admin/organizations/{$organization->id}/resend-owner-password-reset",
    );

    $response
        ->assertNotFound()
        ->assertJsonPath('message', 'No organization owner found for this organization.');
});

it('forbids non-admin users from resending owner password reset links', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    $intruder = User::factory()->create();

    Sanctum::actingAs($intruder);

    $response = $this->postJson(
        "/api/v1/admin/organizations/{$organization->id}/resend-owner-password-reset",
    );

    $response->assertForbidden();
});
