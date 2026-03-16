<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('allows business admins to create restaurants and invite owners', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $organizationResponse = $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Admin Org',
        'slug' => 'admin-org',
    ]);

    $organizationResponse->assertCreated()
        ->assertJsonPath('organization.slug', 'admin-org');

    $organizationId = $organizationResponse->json('organization.id');

    $restaurantResponse = $this->postJson('/api/v1/admin/restaurants', [
        'organization_id' => $organizationId,
        'name' => 'Admin Restaurant',
        'slug' => 'admin-restaurant',
        'status' => 'active',
    ]);

    $restaurantResponse->assertCreated()
        ->assertJsonPath('restaurant.slug', 'admin-restaurant');

    $restaurantId = $restaurantResponse->json('restaurant.id');

    $inviteResponse = $this->postJson('/api/v1/admin/restaurants/'.$restaurantId.'/invite-owner', [
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner@example.com',
        'phone' => '+2348077777777',
        'password' => 'OwnerPass123!',
        'password_confirmation' => 'OwnerPass123!',
    ]);

    $inviteResponse->assertCreated()
        ->assertJsonPath('user.email', 'owner@example.com');
});

it('returns audit logs to authorized admins', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    AuditLog::factory()->create([
        'action' => 'restaurant.updated',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/audit-logs');

    $response->assertOk()
        ->assertJsonFragment(['action' => 'restaurant.updated']);
});
