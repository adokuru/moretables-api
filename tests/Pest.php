<?php

use App\BillingPlanSlug;
use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
use App\Models\RestaurantHour;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ScopedRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Grants a staff member exactly the given permissions for a restaurant via a
 * fresh custom RestaurantAccessConfig (not one of the 5 named defaults) —
 * used to test that a single specific permission alone (without the broader
 * restaurants.manage/restaurants.view) is sufficient for a given check.
 *
 * @param  list<string>  $permissions
 */
function grantAccessConfigPermissions(User $user, Restaurant $restaurant, array $permissions): void
{
    $config = RestaurantAccessConfig::query()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Test Config',
        'slug' => 'test-config-'.Str::random(8),
        'description' => 'Test-only access config.',
        'permissions' => $permissions,
        'is_default' => false,
    ]);

    app(ScopedRoleAssignmentService::class)->syncRestaurantAccessConfig(
        user: $user,
        restaurant: $restaurant,
        accessConfig: $config,
        assignedBy: $user->id,
    );
}

function assignScopedRole(
    User $user,
    string $roleName,
    ?Organization $organization = null,
    ?Restaurant $restaurant = null,
    ?User $assignedBy = null,
): void {
    $roleId = Role::query()->where('name', $roleName)->value('id');

    if (! $roleId) {
        return;
    }

    UserRole::query()->create([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'scope_type' => $restaurant ? 'restaurant' : ($organization ? 'organization' : null),
        'organization_id' => $organization?->id,
        'restaurant_id' => $restaurant?->id,
        'assigned_by' => $assignedBy?->id ?? $user->id,
    ]);
}

/**
 * @return array{organization: Organization, restaurant: Restaurant, table: RestaurantTable}
 */
function createBookableRestaurant(): array
{
    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
    ]);

    RestaurantPolicy::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    foreach (range(0, 6) as $day) {
        RestaurantHour::factory()->create([
            'restaurant_id' => $restaurant->id,
            'day_of_week' => $day,
        ]);
    }

    $table = RestaurantTable::factory()->create([
        'restaurant_id' => $restaurant->id,
        'dining_area_id' => null,
        'max_capacity' => 4,
    ]);

    return compact('organization', 'restaurant', 'table');
}

/**
 * @return array{organization: Organization, restaurant: Restaurant, table: RestaurantTable}
 */
function createListedBookableRestaurant(): array
{
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    markRestaurantOnboardingComplete($data['restaurant']);

    return $data;
}

function markRestaurantOnboardingComplete(Restaurant $restaurant): void
{
    $restaurant->update(['is_profile_published' => true]);

    $hasBookableTimes = $restaurant->shifts()->where('is_active', true)->exists()
        || $restaurant->availabilitySchedules()->exists()
        || $restaurant->hours()->where('is_closed', false)->exists();

    if (! $hasBookableTimes) {
        foreach (range(0, 6) as $day) {
            RestaurantHour::factory()->create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $day,
            ]);
        }
    }
}

function activateMerchantBilling(Restaurant $restaurant): void
{
    $planId = BillingPlan::query()->where('slug', 'foundation')->value('id');

    if (! $planId) {
        $planId = BillingPlan::factory()->create([
            'slug' => 'foundation',
            'name' => 'Foundation',
        ])->id;
    }

    MerchantSubscription::factory()->create([
        'restaurant_id' => $restaurant->id,
        'billing_plan_id' => $planId,
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);
}

/**
 * Gives the business itself an active subscription — the model every restaurant it owns inherits
 * from, up to the plan's restaurant allowance.
 */
function activateBusinessBilling(Organization $organization, string $planSlug = 'premium'): MerchantSubscription
{
    $planId = BillingPlan::query()->where('slug', $planSlug)->value('id');

    if (! $planId) {
        $planId = BillingPlan::factory()->create([
            'slug' => $planSlug,
            'name' => Str::title($planSlug),
        ])->id;
    }

    return MerchantSubscription::factory()
        ->forBusiness($organization)
        ->create([
            'billing_plan_id' => $planId,
            'status' => MerchantSubscriptionStatus::Active,
            'current_period_end' => now()->addMonth(),
        ]);
}

/**
 * Moves a restaurant's already-active subscription (see activateMerchantBilling())
 * onto the given plan tier — used by tests that need to assert plan-tier-gated
 * behavior (e.g. Premium-only survey customization) rather than the default
 * Foundation tier activateMerchantBilling() sets up.
 */
function setRestaurantBillingPlan(Restaurant $restaurant, BillingPlanSlug $slug): void
{
    $planId = BillingPlan::query()->where('slug', $slug->value)->value('id');

    if (! $planId) {
        $planId = BillingPlan::factory()->create(['slug' => $slug])->id;
    }

    $restaurant->activeBillingSubscription()->update(['billing_plan_id' => $planId]);
}

function createListedRestaurant(array $attributes = []): Restaurant
{
    $restaurant = Restaurant::factory()->create($attributes);
    activateMerchantBilling($restaurant);
    markRestaurantOnboardingComplete($restaurant);

    return $restaurant;
}
