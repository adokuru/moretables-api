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
