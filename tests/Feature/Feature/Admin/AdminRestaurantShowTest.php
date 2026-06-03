<?php

use App\Models\BillingPlan;
use App\Models\CuisineOption;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantRewardRule;
use App\Models\RestaurantSocialHandle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('returns full restaurant details for admin show', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create([
        'name' => 'Full Detail Org',
        'slug' => 'full-detail-org',
    ]);

    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Full Detail Restaurant',
        'slug' => 'full-detail-restaurant',
        'internal_notes' => 'Admin-only notes',
        'rewards_enabled' => true,
        'reservation_reward_points' => 25,
    ]);

    RestaurantPolicy::factory()->create([
        'restaurant_id' => $restaurant->id,
        'max_party_size' => 10,
    ]);

    $cuisine = CuisineOption::factory()->create(['name' => 'Fusion']);
    $restaurant->cuisines()->attach($cuisine->id, ['is_primary' => true]);

    RestaurantSocialHandle::query()->create([
        'restaurant_id' => $restaurant->id,
        'platform' => 'instagram',
        'handle' => '@fulldetail',
    ]);

    RestaurantRewardRule::factory()->create([
        'restaurant_id' => $restaurant->id,
        'points' => 50,
    ]);

    $plan = BillingPlan::query()->where('slug', 'core')->firstOrFail();

    MerchantSubscription::factory()->create([
        'restaurant_id' => $restaurant->id,
        'billing_plan_id' => $plan->id,
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/restaurants/'.$restaurant->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $restaurant->id)
        ->assertJsonPath('data.name', 'Full Detail Restaurant')
        ->assertJsonPath('data.internal_notes', 'Admin-only notes')
        ->assertJsonPath('data.rewards_enabled', true)
        ->assertJsonPath('data.reservation_reward_points', 25)
        ->assertJsonPath('data.organization.name', 'Full Detail Org')
        ->assertJsonPath('data.policy.max_party_size', 10)
        ->assertJsonPath('data.cuisine_options.0.name', 'Fusion')
        ->assertJsonPath('data.cuisine_options.0.is_primary', true)
        ->assertJsonPath('data.social_handles.0.platform', 'instagram')
        ->assertJsonPath('data.reward_rules.0.points', 50)
        ->assertJsonPath('data.active_billing_subscription.plan.slug', 'core')
        ->assertJsonPath('data.discovery_metrics.reviews_count', 0)
        ->assertJsonStructure([
            'data' => [
                'hours',
                'meal_types',
                'menus',
                'dining_areas',
                'stats',
                'staff',
                'access_configs',
                'created_at',
                'updated_at',
            ],
        ]);
});
