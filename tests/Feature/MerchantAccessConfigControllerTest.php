<?php

use App\BillingPlanSlug;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create();
    // Customized User Permissions (creating a new access config) is Core/Premium-only
    // (docs/PLAN_PERMISSIONS.md) — default to Premium; the plan-gating tests below
    // explicitly downgrade instead.
    activateMerchantBilling($this->restaurant);
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Premium);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

function validAccessConfigPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Custom Front Desk',
        'permissions' => ['reservations.view', 'reservations.manage'],
    ], $overrides);
}

it('lists the 5 default access configs for a fresh restaurant', function (): void {
    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs")
        ->assertSuccessful()
        ->assertJsonCount(5, 'access_configs');
});

it('creates a custom access config', function (): void {
    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs",
        validAccessConfigPayload(),
    )->assertCreated()
        ->assertJsonPath('access_config.name', 'Custom Front Desk')
        ->assertJsonPath('access_config.is_default', false);
});

it('forbids users without staff.manage from creating an access config', function (): void {
    $viewer = User::factory()->create();
    assignScopedRole($viewer, Role::GuestRelations, $this->organization, $this->restaurant);
    Sanctum::actingAs($viewer);

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs",
        validAccessConfigPayload(),
    )->assertForbidden();
});

// Plan-tier gating — Customized User Permissions is Core/Premium-only
// (docs/PLAN_PERMISSIONS.md). $this->restaurant is Premium by default (see beforeEach);
// these tests explicitly downgrade it.

it('rejects creating a custom access config for a restaurant below Core with an upgrade message', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs",
        validAccessConfigPayload(),
    )->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Core or Premium to create custom access configs.');

    expect($this->restaurant->accessConfigs()->count())->toBe(5);
});

it('still allows listing and assigning staff to the 5 default configs for a restaurant below Core', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs")
        ->assertSuccessful()
        ->assertJsonCount(5, 'access_configs');

    $principalAdminId = collect($response->json('access_configs'))
        ->firstWhere('slug', 'principal_admin')['id'];

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/staff", [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'access_config_id' => $principalAdminId,
    ])->assertSuccessful();
});

it('allows creating a custom access config on the Core plan (not just Premium)', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Core);

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/access-configs",
        validAccessConfigPayload(),
    )->assertCreated();
});
