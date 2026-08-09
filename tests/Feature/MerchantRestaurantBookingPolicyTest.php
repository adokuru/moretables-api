<?php

use App\Models\Organization;
use App\Models\RestaurantPolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = createListedRestaurant([
        'organization_id' => $this->organization->id,
    ]);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

function validCustomDiningPolicyText(): string
{
    return 'Guests are required to arrive within fifteen minutes of their reserved time. Reservations held beyond this window may be released to other diners.';
}

it('allows a staff member with only policies.manage (no restaurants.view/manage) to view and update the booking policy', function (): void {
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $this->restaurant, ['policies.manage']);
    Sanctum::actingAs($staff);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy")->assertSuccessful();

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy", [
        'booking_details_locale' => 'fr',
    ])->assertSuccessful();
});

it('forbids a staff member without policies.manage or restaurants.view/manage from viewing the booking policy', function (): void {
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $this->restaurant, ['reservations.view']);
    Sanctum::actingAs($staff);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy")->assertForbidden();
});

it('shows booking policy and creates a default policy row when missing', function (): void {
    expect(RestaurantPolicy::query()->where('restaurant_id', $this->restaurant->id)->exists())->toBeFalse();

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy");

    $response->assertSuccessful()
        ->assertJsonPath('data.booking_details_locale', 'en')
        ->assertJsonPath('data.custom_dining_policy', null)
        ->assertJsonPath('data.custom_dining_policy_min_length', 100)
        ->assertJsonPath('data.custom_dining_policy_max_length', 1000);

    expect(RestaurantPolicy::query()->where('restaurant_id', $this->restaurant->id)->exists())->toBeTrue();
});

it('updates booking policy details for restaurant managers', function (): void {
    RestaurantPolicy::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'booking_details_locale' => 'en',
    ]);

    $response = patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy", [
        'booking_details_locale' => 'fr',
        'custom_dining_policy' => validCustomDiningPolicyText(),
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.booking_details_locale', 'fr')
        ->assertJsonPath('data.custom_dining_policy', validCustomDiningPolicyText());

    $this->assertDatabaseHas('restaurant_policies', [
        'restaurant_id' => $this->restaurant->id,
        'booking_details_locale' => 'fr',
    ]);
});

it('validates custom dining policy length', function (): void {
    RestaurantPolicy::factory()->create([
        'restaurant_id' => $this->restaurant->id,
    ]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy", [
        'custom_dining_policy' => 'Too short.',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_dining_policy']);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy", [
        'custom_dining_policy' => str_repeat('a', 1001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_dining_policy']);
});

it('forbids users without restaurant manage permission from updating booking policy', function (): void {
    $viewer = User::factory()->create();
    assignScopedRole($viewer, Role::GuestRelations, $this->organization, $this->restaurant);
    Sanctum::actingAs($viewer);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/booking-policy", [
        'custom_dining_policy' => validCustomDiningPolicyText(),
    ])->assertForbidden();
});
