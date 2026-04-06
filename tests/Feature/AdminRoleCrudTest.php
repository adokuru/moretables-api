<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('allows admins to manage custom roles', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    Sanctum::actingAs($admin);

    $listResponse = $this->getJson('/api/v1/admin/roles');

    $listResponse->assertOk()
        ->assertJsonStructure(['roles']);

    $permissionNames = Permission::query()->orderBy('name')->limit(2)->pluck('name')->values()->all();

    $createResponse = $this->postJson('/api/v1/admin/roles', [
        'name' => 'support_specialist',
        'description' => 'Handles support escalations.',
        'permissions' => $permissionNames,
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('role.name', 'support_specialist')
        ->assertJsonPath('role.description', 'Handles support escalations.')
        ->assertJsonCount(count($permissionNames), 'role.permissions');

    $roleId = $createResponse->json('role.id');

    $showResponse = $this->getJson('/api/v1/admin/roles/'.$roleId);

    $showResponse->assertOk()
        ->assertJsonPath('data.name', 'support_specialist');

    $updatedPermissionNames = Permission::query()->orderByDesc('name')->limit(1)->pluck('name')->values()->all();

    $updateResponse = $this->patchJson('/api/v1/admin/roles/'.$roleId, [
        'description' => 'Handles advanced support workflows.',
        'permissions' => $updatedPermissionNames,
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('role.description', 'Handles advanced support workflows.')
        ->assertJsonCount(count($updatedPermissionNames), 'role.permissions');

    $deleteResponse = $this->deleteJson('/api/v1/admin/roles/'.$roleId);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'Role deleted successfully.');

    expect(Role::query()->whereKey($roleId)->exists())->toBeFalse();
});

it('prevents admins from renaming built in roles', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    Sanctum::actingAs($admin);

    $role = Role::query()->where('name', Role::BusinessAdmin)->firstOrFail();

    $response = $this->patchJson('/api/v1/admin/roles/'.$role->id, [
        'name' => 'business_admin_plus',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('prevents admins from deleting assigned roles', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    $staff = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);
    assignScopedRole($staff, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $role = Role::query()->where('name', Role::BusinessAdmin)->firstOrFail();

    $response = $this->deleteJson('/api/v1/admin/roles/'.$role->id);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'This role is currently assigned to one or more users.');
});
