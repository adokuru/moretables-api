<?php

use App\BillingPlanSlug;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create([
        'rewards_enabled' => true,
        'reservation_reward_points' => 100,
    ]);
    // Guest Loyalty Program is Core/Premium-only (docs/PLAN_PERMISSIONS.md) — this file's
    // existing tests predate plan-tier gating and expect rewards_enabled alone to be
    // sufficient, so keep the restaurant on Premium by default.
    activateMerchantBilling($this->restaurant);
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Premium);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('reports that a restaurant offers credits with the default score', function (): void {
    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.restaurant_id', $this->restaurant->id)
        ->assertJsonPath('rewards.offers_credits', true)
        ->assertJsonPath('rewards.has_custom_score', false)
        ->assertJsonPath('rewards.reservation_reward_points', 100)
        ->assertJsonPath('rewards.default_reward_points', 100);
});

it('flags when the restaurant has entered a custom score', function (): void {
    $this->restaurant->update(['reservation_reward_points' => 250]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', true)
        ->assertJsonPath('rewards.has_custom_score', true)
        ->assertJsonPath('rewards.reservation_reward_points', 250);
});

it('reports when a restaurant does not offer credits', function (): void {
    $this->restaurant->update(['rewards_enabled' => false]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', false);
});

it('reports that a restaurant below Core does not offer credits, even with rewards_enabled true', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', false);
});

it('reports that a restaurant on Core (not just Premium) offers credits', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Core);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', true);
});

it('prevents checking the reward status of another restaurant', function (): void {
    $otherRestaurant = Restaurant::factory()->create();
    // Needs its own active billing subscription, or EnsureMerchantBillingActive short-circuits
    // to a 402 (unbillable restaurant) before the controller's own permission check ever runs
    // — this test is about permission scoping, not billing state.
    activateMerchantBilling($otherRestaurant);

    getJson("/api/v1/merchant/restaurants/{$otherRestaurant->id}/rewards/status")
        ->assertForbidden();
});
