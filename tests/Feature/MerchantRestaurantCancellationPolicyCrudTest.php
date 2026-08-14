<?php

use App\BillingPlanSlug;
use App\Enums\CancellationPolicyManagementMethod;
use App\Enums\CancellationPolicyPartySizeScope;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = createListedRestaurant([
        'organization_id' => $this->organization->id,
    ]);
    // Reservation Holds is Core/Premium-only (docs/PLAN_PERMISSIONS.md) — this file's
    // existing tests predate that gate and expect creating/editing to just work, so
    // default to Premium. The plan-gating tests further down explicitly downgrade instead.
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Premium);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

function validCancellationPolicyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Weekend card hold',
        'management_method' => CancellationPolicyManagementMethod::CardHold->value,
        'party_size_scope' => CancellationPolicyPartySizeScope::AllPartySizes->value,
        'hold_charge_amount' => 5000,
        'starts_on' => '2026-01-23',
        'ends_on' => '2026-03-23',
        'days' => [1],
        'start_time' => '18:00',
        'end_time' => '22:00',
        'is_active' => true,
    ], $overrides);
}

it('allows a staff member with only policies.manage (no restaurants.view/manage) to list and create cancellation policies', function (): void {
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $this->restaurant, ['policies.manage']);
    Sanctum::actingAs($staff);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies")->assertSuccessful();

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(),
    )->assertCreated();
});

it('lists cancellation policies for a restaurant', function (): void {
    RestaurantCancellationPolicy::factory()->count(2)->create([
        'restaurant_id' => $this->restaurant->id,
    ]);

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies");

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(2);
});

it('creates multiple cancellation policies for a restaurant', function (): void {
    $first = postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(['name' => 'Weekday policy']),
    );

    $first->assertCreated()
        ->assertJsonPath('data.name', 'Weekday policy')
        ->assertJsonPath('data.sort_order', 1);

    $second = postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(['name' => 'Weekend policy', 'days' => [5, 6]]),
    );

    $second->assertCreated()
        ->assertJsonPath('data.name', 'Weekend policy')
        ->assertJsonPath('data.sort_order', 2);

    expect(RestaurantCancellationPolicy::query()->where('restaurant_id', $this->restaurant->id)->count())->toBe(2);
});

it('shows updates and deletes a cancellation policy', function (): void {
    $policy = RestaurantCancellationPolicy::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Original policy',
    ]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Original policy');

    patchJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}",
        ['name' => 'Updated policy', 'hold_charge_amount' => 7500],
    )->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated policy')
        ->assertJsonPath('data.hold_charge_amount', 7500);

    deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Cancellation policy deleted successfully.');

    $this->assertDatabaseMissing('restaurant_cancellation_policies', ['id' => $policy->id]);
});

it('requires custom party size bounds when scope is custom', function (): void {
    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload([
            'party_size_scope' => CancellationPolicyPartySizeScope::Custom->value,
        ]),
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['min_party_size', 'max_party_size']);
});

it('returns not found when cancellation policy belongs to another restaurant', function (): void {
    $otherRestaurant = Restaurant::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $policy = RestaurantCancellationPolicy::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
    ]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}")
        ->assertNotFound();
});

it('forbids users without restaurant manage permission from creating cancellation policies', function (): void {
    $viewer = User::factory()->create();
    assignScopedRole($viewer, Role::GuestRelations, $this->organization, $this->restaurant);
    Sanctum::actingAs($viewer);

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(),
    )->assertForbidden();
});

// Plan-tier gating — Reservation Holds is Core/Premium-only (docs/PLAN_PERMISSIONS.md).
// $this->restaurant is Premium by default (see beforeEach); these tests explicitly
// downgrade it.

it('rejects creating a cancellation policy for a restaurant below Core with an upgrade message', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(),
    )->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Core or Premium to set up reservation holds.');

    expect(RestaurantCancellationPolicy::query()->where('restaurant_id', $this->restaurant->id)->count())->toBe(0);
});

it('rejects updating a cancellation policy for a restaurant below Core with an upgrade message', function (): void {
    $policy = RestaurantCancellationPolicy::factory()->create(['restaurant_id' => $this->restaurant->id, 'name' => 'Original']);
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    patchJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}",
        ['name' => 'Changed'],
    )->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Core or Premium to set up reservation holds.');

    expect($policy->refresh()->name)->toBe('Original');
});

it('still allows deleting an existing cancellation policy for a restaurant below Core', function (): void {
    $policy = RestaurantCancellationPolicy::factory()->create(['restaurant_id' => $this->restaurant->id]);
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policy->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('restaurant_cancellation_policies', ['id' => $policy->id]);
});

it('allows creating and updating cancellation policies on the Core plan (not just Premium)', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Core);

    $response = postJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies",
        validCancellationPolicyPayload(),
    )->assertCreated();

    $policyId = $response->json('data.id');

    patchJson(
        "/api/v1/merchant/restaurants/{$this->restaurant->id}/cancellation-policies/{$policyId}",
        ['name' => 'Updated on Core'],
    )->assertSuccessful()->assertJsonPath('data.name', 'Updated on Core');
});
