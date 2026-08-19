<?php

use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\Restaurant;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

function payingRestaurant(string $planSlug, int $restaurantCount = 1): array
{
    $organization = Organization::factory()->create();
    $restaurants = Restaurant::factory()->count($restaurantCount)->create([
        'organization_id' => $organization->id,
    ]);

    $subscription = MerchantSubscription::factory()->create([
        'restaurant_id' => $restaurants->first()->id,
        'organization_id' => $organization->id,
        'billing_plan_id' => BillingPlan::query()->where('slug', $planSlug)->value('id'),
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    return [$organization, $restaurants, $subscription];
}

it('promotes a paid restaurant subscription to the business that owns it', function (): void {
    [$organization, $restaurants, $subscription] = payingRestaurant('core');

    $this->artisan('billing:promote-subscriptions-to-business')
        ->assertSuccessful();

    $subscription->refresh();

    expect($subscription->restaurant_id)->toBeNull()
        ->and($subscription->organization_id)->toBe($organization->id)
        ->and($subscription->isBusinessLevel())->toBeTrue()
        ->and($subscription->metadata['promoted_from_restaurant_id'])->toBe($restaurants->first()->id)
        ->and($restaurants->first()->fresh()->effectiveBillingSubscription()?->id)->toBe($subscription->id);
});

it('keeps serving every restaurant of the business after promoting an unlimited plan', function (): void {
    [, $restaurants] = payingRestaurant('premium', restaurantCount: 3);

    $this->artisan('billing:promote-subscriptions-to-business')->assertSuccessful();

    foreach ($restaurants as $restaurant) {
        expect($restaurant->fresh()->effectiveBillingSubscription())->not->toBeNull();
    }
});

it('leaves a subscription alone when the plan cannot cover every restaurant the business owns', function (): void {
    [, $restaurants, $subscription] = payingRestaurant('core', restaurantCount: 2);

    $this->artisan('billing:promote-subscriptions-to-business')
        ->expectsOutputToContain('Core covers 1 restaurant(s), business has 2')
        ->assertSuccessful();

    expect($subscription->refresh()->restaurant_id)->toBe($restaurants->first()->id)
        ->and($restaurants->first()->fresh()->effectiveBillingSubscription()?->id)->toBe($subscription->id)
        ->and($restaurants->last()->fresh()->effectiveBillingSubscription())->toBeNull();
});

it('leaves a business alone when more than one of its restaurants pays separately', function (): void {
    [$organization, $restaurants] = payingRestaurant('premium', restaurantCount: 2);

    MerchantSubscription::factory()->create([
        'restaurant_id' => $restaurants->last()->id,
        'organization_id' => $organization->id,
        'billing_plan_id' => BillingPlan::query()->where('slug', 'core')->value('id'),
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $this->artisan('billing:promote-subscriptions-to-business')
        ->expectsOutputToContain('2 live restaurant subscriptions')
        ->assertSuccessful();

    expect(MerchantSubscription::query()->whereNull('restaurant_id')->exists())->toBeFalse();
});

it('changes nothing on a dry run', function (): void {
    [, $restaurants, $subscription] = payingRestaurant('core');

    $this->artisan('billing:promote-subscriptions-to-business', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 subscription(s) would be promoted')
        ->assertSuccessful();

    expect($subscription->refresh()->restaurant_id)->toBe($restaurants->first()->id);
});

it('refuses to run when business billing is switched off', function (): void {
    config(['billing.scope' => 'restaurant']);

    $this->artisan('billing:promote-subscriptions-to-business')->assertFailed();
});
