<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('records admin audit logs when admins create users', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Audit',
        'last_name' => 'Customer',
        'email' => 'audit.customer@example.com',
        'account_type' => 'customer',
    ]);

    $response->assertCreated();

    expect(AuditLog::query()->where('action', 'admin.user.created')->exists())->toBeTrue();
});

it('returns admin audit logs with search and actor filters', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create(['email' => 'audit.admin@example.com']);
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create(['name' => 'Audit Org']);

    AuditLog::factory()->create([
        'actor_user_id' => $admin->id,
        'organization_id' => $organization->id,
        'action' => 'admin.organization.updated',
        'description' => 'Updated billing email',
    ]);

    AuditLog::factory()->create([
        'action' => 'restaurant.updated',
        'description' => 'Merchant-side update',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/audit-logs?search=organization&actor_user_id='.$admin->id.'&per_page=10');

    $response->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'admin.organization.updated')
        ->assertJsonPath('data.0.actor.email', 'audit.admin@example.com')
        ->assertJsonPath('data.0.organization.id', $organization->id)
        ->assertJsonPath('data.0.organization.name', 'Audit Org');
});

it('returns admin audit logs when searching by actor name', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'first_name' => 'Searchable',
        'last_name' => 'AdminActor',
        'name' => 'Searchable AdminActor',
        'email' => 'searchable.admin@example.com',
    ]);
    assignScopedRole($admin, Role::BusinessAdmin);

    AuditLog::factory()->create([
        'actor_user_id' => $admin->id,
        'action' => 'admin.user.updated',
        'description' => 'Changed phone number',
    ]);

    AuditLog::factory()->create([
        'action' => 'restaurant.updated',
        'description' => 'Unrelated merchant update',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/audit-logs?search=Searchable+AdminActor');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'admin.user.updated')
        ->assertJsonPath('data.0.actor.email', 'searchable.admin@example.com');
});

it('records admin audit log when assigning a billing subscription', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::DevAdmin);

    $restaurant = Restaurant::factory()->create();

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'foundation',
        'notes' => 'Manual assign for audit test',
    ])->assertCreated();

    $log = AuditLog::query()->where('action', 'admin.billing.subscription.assigned')->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->restaurant_id)->toBe($restaurant->id)
        ->and($log->description)->toBe('Manual assign for audit test');
});
