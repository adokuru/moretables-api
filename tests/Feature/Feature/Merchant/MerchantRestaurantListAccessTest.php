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

it('withholds can_access_admin from a staff member invited with the default Operations access config\'s day-to-day permissions only', function (): void {
    // Operations grants reservations.manage/tables.manage/waitlist.manage/staff.manage —
    // staff.manage now unlocks the User Management "Add"/"Edit" actions in the dashboard
    // (a deliberate, explicit exception — Operations should be able to manage staff), but
    // none of Operations' permissions are in Permission::adminSectionPermissions(), so it
    // should NOT unlock the /admin back-office section itself.
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

    expect($restaurant['can_access_admin'])->toBeFalse()
        ->and($restaurant['permissions'])->toContain('staff.manage')
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
