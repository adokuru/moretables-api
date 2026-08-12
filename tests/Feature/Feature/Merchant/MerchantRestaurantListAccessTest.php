<?php

use App\Models\Organization;
use App\Models\RestaurantAccessConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\ScopedRoleAssignmentService;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = createListedRestaurant(['organization_id' => $this->organization->id]);
});

it('grants can_access_admin to a staff member invited with the default Principal Admin access config', function (): void {
    $staff = User::factory()->create();
    $config = RestaurantAccessConfig::query()
        ->where('restaurant_id', $this->restaurant->id)
        ->where('slug', 'principal_admin')
        ->firstOrFail();

    app(ScopedRoleAssignmentService::class)->syncRestaurantAccessConfig(
        user: $staff,
        restaurant: $this->restaurant,
        accessConfig: $config,
        assignedBy: $staff->id,
    );

    Sanctum::actingAs($staff);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    expect($restaurant['can_access_admin'])->toBeTrue()
        ->and($restaurant['permissions'])->toContain('staff.manage', 'restaurants.manage');
});

it('grants can_access_admin to a staff member invited with the default Operations access config, via staff.manage/tables.manage/restaurants.view', function (): void {
    // Operations grants restaurants.view/reservations.manage/tables.manage/waitlist.manage/
    // staff.manage. Each of restaurants.view (Restaurant Profile, view-only), tables.manage
    // (Availability Planning), and staff.manage (Accounts) is now in
    // Permission::adminSectionPermissions() — every permission that gates a real /admin
    // page unlocks /admin itself, so Operations can reach /admin (scoped to just those
    // pages via the frontend's per-page gating) even though it still lacks the broad
    // restaurants.manage/billing.manage/etc. permissions.
    $staff = User::factory()->create();
    $config = RestaurantAccessConfig::query()
        ->where('restaurant_id', $this->restaurant->id)
        ->where('slug', 'operations')
        ->firstOrFail();

    app(ScopedRoleAssignmentService::class)->syncRestaurantAccessConfig(
        user: $staff,
        restaurant: $this->restaurant,
        accessConfig: $config,
        assignedBy: $staff->id,
    );

    Sanctum::actingAs($staff);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    expect($restaurant['can_access_admin'])->toBeTrue()
        ->and($restaurant['permissions'])->toContain('staff.manage', 'tables.manage', 'restaurants.view')
        ->and($restaurant['permissions'])->not->toContain('restaurants.manage', 'billing.manage');
});

it('grants can_access_admin to a staff member invited with a custom access config carrying an admin-only permission', function (): void {
    $staff = User::factory()->create();
    $config = RestaurantAccessConfig::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Marketing Lead',
        'slug' => 'marketing-lead',
        'description' => 'Runs promotions',
        'permissions' => ['restaurants.view', 'marketing.manage'],
        'is_default' => false,
    ]);

    app(ScopedRoleAssignmentService::class)->syncRestaurantAccessConfig(
        user: $staff,
        restaurant: $this->restaurant,
        accessConfig: $config,
        assignedBy: $staff->id,
    );

    Sanctum::actingAs($staff);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    expect($restaurant['can_access_admin'])->toBeTrue()
        ->and($restaurant['permissions'])->toContain('marketing.manage');
});

it('grants can_access_admin to a classic OrganizationOwner without any access config', function (): void {
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $this->organization);

    Sanctum::actingAs($owner);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    expect($restaurant['can_access_admin'])->toBeTrue();
});

it('returns the assigned access config\'s own name as role_name, not the legacy stamped role_id', function (): void {
    $staff = User::factory()->create();
    $config = RestaurantAccessConfig::query()
        ->where('restaurant_id', $this->restaurant->id)
        ->where('slug', 'marketing_growth')
        ->firstOrFail();

    app(ScopedRoleAssignmentService::class)->syncRestaurantAccessConfig(
        user: $staff,
        restaurant: $this->restaurant,
        accessConfig: $config,
        assignedBy: $staff->id,
    );

    Sanctum::actingAs($staff);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    // syncRestaurantAccessConfig stamps role_id as principal_admin for every
    // access-config-based assignment (see ADMIN_ACCESS_CONTROL.md) — role_name
    // must reflect the real config ("Marketing & Growth"), not that stand-in.
    expect($restaurant['role_name'])->toBe('Marketing & Growth');
});

it('returns a title-cased classic role name as role_name when there is no access config', function (): void {
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $this->organization);

    Sanctum::actingAs($owner);

    $response = getJson('/api/v1/merchant/restaurants');

    $response->assertSuccessful();
    $restaurant = collect($response->json('restaurants'))->firstWhere('id', $this->restaurant->id);

    expect($restaurant['role_name'])->toBe('Organization Owner');
});
