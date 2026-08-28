<?php

use App\BillingPlanSlug;
use App\Models\BillingPlan;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create(['rewards_enabled' => false]);
    activateMerchantBilling($this->restaurant);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

// Guest Loyalty Program is Core/Premium-only (docs/PLAN_PERMISSIONS.md). The onboarding
// "I agree to participate" checkbox saves through this generic settings endpoint — a
// restaurant below Core can't opt in no matter what it submits.

it('auto-disagrees rewards_enabled for a restaurant below Core', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/settings", [
        'rewards_enabled' => true,
    ])->assertOk()->assertJsonPath('settings.rewards_enabled', false);

    expect($this->restaurant->refresh()->rewards_enabled)->toBeFalse();
});

it('allows opting in on the Core plan (not just Premium)', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Core);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/settings", [
        'rewards_enabled' => true,
    ])->assertOk()->assertJsonPath('settings.rewards_enabled', true);

    expect($this->restaurant->refresh()->rewards_enabled)->toBeTrue();
});

it('allows opting out below Core (submitting false is not coerced)', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);
    $this->restaurant->update(['rewards_enabled' => false]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/settings", [
        'rewards_enabled' => false,
    ])->assertOk()->assertJsonPath('settings.rewards_enabled', false);
});

// The Restaurant Settings page's "Price and Planning" row showed a plan's raw
// kobo amount as if it were naira (e.g. ₦18,500,000/month for a plan that
// actually costs ₦185,000/month) — every other place this app displays a
// plan/invoice amount (BillingPlanResource, MerchantInvoiceResource,
// RestaurantBillingSummaryResource, ...) already exposes a pre-divided
// `display_amount` string (`number_format($amount / 100, 2)`) alongside the
// raw `amount`; this endpoint was the one place that skipped it.
it('exposes the plan price pre-divided from kobo to naira as display_amount', function (): void {
    $plan = BillingPlan::factory()->create([
        'slug' => BillingPlanSlug::Premium,
        'amount' => 18500000,
    ]);
    $this->restaurant->activeBillingSubscription()->update(['billing_plan_id' => $plan->id]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/settings")
        ->assertOk()
        ->assertJsonPath('settings.plan.amount', 18500000)
        ->assertJsonPath('settings.plan.display_amount', '185,000.00');
});

it('leaves other settings fields untouched by the rewards auto-disagree', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/settings", [
        'name' => 'Updated Name',
        'rewards_enabled' => true,
    ])->assertOk()
        ->assertJsonPath('settings.name', 'Updated Name')
        ->assertJsonPath('settings.rewards_enabled', false);
});
