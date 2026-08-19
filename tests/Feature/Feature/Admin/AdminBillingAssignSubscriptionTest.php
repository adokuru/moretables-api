<?php

use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantPayment;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

it('allows admins to list billing plans and assign a subscription to a restaurant', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Unsubscribed Bistro',
    ]);

    Sanctum::actingAs($admin);

    $plansResponse = $this->getJson('/api/v1/admin/billing/plans');

    $plansResponse->assertOk()
        ->assertJsonPath('plans.0.slug', 'foundation');

    $assignResponse = $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'core',
        'status' => 'active',
        'notes' => 'Offline payment received',
    ]);

    $assignResponse->assertCreated()
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('subscription.plan.slug', 'core')
        ->assertJsonPath('subscription.restaurant.id', $restaurant->id)
        ->assertJsonPath('subscription.organization.id', $organization->id)
        ->assertJsonPath('subscription.metadata.source', 'admin_assignment')
        ->assertJsonPath('invoice.status', 'paid');

    $subscription = MerchantSubscription::query()
        ->where('restaurant_id', $restaurant->id)
        ->where('status', 'active')
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->provider_subscription_code)->toStartWith('admin_')
        ->and(MerchantInvoice::query()->where('merchant_subscription_id', $subscription->id)->exists())->toBeTrue()
        ->and(MerchantPayment::query()->where('merchant_subscription_id', $subscription->id)->where('status', 'success')->exists())->toBeTrue();
});

it('returns the billing plan for a restaurant with its organization context', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create([
        'name' => 'Plan Org',
        'billing_email' => 'billing@plan-org.example',
    ]);
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Plan Bistro',
    ]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'core',
        'status' => 'active',
    ])->assertCreated();

    $response = $this->getJson('/api/v1/admin/restaurants/'.$restaurant->id.'/billing/plan');

    $response->assertOk()
        ->assertJsonPath('organization.id', $organization->id)
        ->assertJsonPath('organization.name', 'Plan Org')
        ->assertJsonPath('organization.billing_email', 'billing@plan-org.example')
        ->assertJsonPath('restaurant.id', $restaurant->id)
        ->assertJsonPath('plan.slug', 'core')
        ->assertJsonPath('subscription.plan.slug', 'core')
        ->assertJsonPath('billing.is_active', true);
});

it('returns null plan when a restaurant has no subscription', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/restaurants/'.$restaurant->id.'/billing/plan');

    $response->assertOk()
        ->assertJsonPath('organization.id', $organization->id)
        ->assertJsonPath('plan', null)
        ->assertJsonPath('subscription', null)
        ->assertJsonPath('billing.is_active', false);
});

it('includes billing plan on admin restaurant index without extra requests', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Indexed Plan Bistro',
    ]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'foundation',
        'status' => 'active',
    ])->assertCreated();

    $response = $this->getJson('/api/v1/admin/restaurants?search=Indexed+Plan+Bistro');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $restaurant->id)
        ->assertJsonPath('data.0.billing.is_active', true)
        ->assertJsonPath('data.0.billing.plan.slug', 'foundation')
        ->assertJsonPath('data.0.plan.slug', 'foundation')
        ->assertJsonPath('data.0.is_subscribed', true)
        ->assertJsonPath('data.0.subscription_type', 'foundation')
        ->assertJsonStructure(['data' => [['created_at', 'updated_at']]]);
});

it('replaces an existing active subscription when admin assigns a new plan', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $restaurant = Restaurant::factory()->create();
    $oldPlan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();
    $newPlan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();

    $existing = MerchantSubscription::factory()->create([
        'restaurant_id' => $restaurant->id,
        'billing_plan_id' => $oldPlan->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'premium',
    ]);

    $response->assertCreated()
        ->assertJsonPath('subscription.plan.slug', 'premium');

    expect($existing->refresh()->status->value)->toBe('canceled')
        ->and(MerchantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'active')
            ->count())->toBe(1)
        ->and(MerchantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'active')
            ->value('billing_plan_id'))->toBe($newPlan->id);
});

it('validates assign subscription payload', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::DevAdmin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['restaurant_id', 'plan']);
});

it('forbids non admins from assigning subscriptions', function (): void {
    $customer = User::factory()->create();
    assignScopedRole($customer, Role::Customer);

    $restaurant = Restaurant::factory()->create();

    Sanctum::actingAs($customer);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'restaurant_id' => $restaurant->id,
        'plan' => 'core',
    ])->assertForbidden();
});

it('allows admins to assign a subscription to a business, covering all of its restaurants', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create();
    $first = Restaurant::factory()->create(['organization_id' => $organization->id]);
    $second = Restaurant::factory()->create(['organization_id' => $organization->id]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'organization_id' => $organization->id,
        'plan' => 'premium',
        'status' => 'active',
        'notes' => 'Group deal',
    ])->assertCreated()
        ->assertJsonPath('subscription.scope', 'business')
        ->assertJsonPath('subscription.restaurant.id', null)
        ->assertJsonPath('subscription.organization.id', $organization->id)
        ->assertJsonPath('subscription.plan.slug', 'premium');

    $subscription = MerchantSubscription::query()
        ->whereNull('restaurant_id')
        ->where('organization_id', $organization->id)
        ->firstOrFail();

    expect($subscription->isBusinessLevel())->toBeTrue()
        ->and($first->fresh()->effectiveBillingSubscription()?->id)->toBe($subscription->id)
        ->and($second->fresh()->effectiveBillingSubscription()?->id)->toBe($subscription->id);

    $this->getJson('/api/v1/admin/businesses/'.$organization->id.'/billing/plan')
        ->assertOk()
        ->assertJsonPath('organization.restaurants_count', 2)
        ->assertJsonPath('plan.slug', 'premium')
        ->assertJsonPath('billing.is_active', true);

    $this->getJson('/api/v1/admin/restaurants/'.$second->id.'/billing/plan')
        ->assertOk()
        ->assertJsonPath('billing_scope', 'business')
        ->assertJsonPath('plan.slug', 'premium');
});

it('refuses a business subscription on a plan that cannot cover all of its restaurants', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create();
    Restaurant::factory()->count(2)->create(['organization_id' => $organization->id]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'organization_id' => $organization->id,
        'plan' => 'foundation',
    ])->assertStatus(422);

    expect(MerchantSubscription::query()->whereNull('restaurant_id')->exists())->toBeFalse();
});

it('requires either a business or a restaurant when assigning a subscription', function (): void {
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/billing/subscriptions', [
        'plan' => 'core',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['organization_id', 'restaurant_id']);
});
