<?php

use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantSubscription;
use App\Models\Role;
use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

it('marks the product tour as completed for an authenticated merchant staff user', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $merchant = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'has_completed_product_tour' => false,
    ]);

    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-password')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/tour');

    $response->assertOk()
        ->assertJsonPath('message', 'Product tour marked as completed.')
        ->assertJsonPath('user.has_completed_product_tour', true);

    $this->assertDatabaseHas('users', [
        'id' => $merchant->id,
        'has_completed_product_tour' => true,
    ]);
});

it('forbids unauthenticated requests to complete the product tour', function () {
    $this->patchJson('/api/v1/auth/staff/profile/tour')->assertUnauthorized();
});

it('still reports an active subscription after completing the product tour', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);

    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();
    MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $merchant = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
        'has_completed_product_tour' => false,
    ]);
    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-password')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/auth/staff/profile/tour')
        ->assertOk()
        ->assertJsonPath('user.subscription.is_active', true)
        ->assertJsonPath('user.subscription.plan_slug', 'premium');
});
