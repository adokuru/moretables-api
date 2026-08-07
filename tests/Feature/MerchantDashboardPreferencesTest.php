<?php

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
    $this->restaurant = createListedRestaurant([
        'organization_id' => $this->organization->id,
    ]);
});

it('shows the default dashboard preferences when none are stored', function (): void {
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::RestaurantStaff, $this->organization, $this->restaurant);
    Sanctum::actingAs($staff);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences")
        ->assertSuccessful()
        ->assertJsonPath('preferences.display_recommended_table_assignment', false)
        ->assertJsonPath('preferences.display_guest_full_name', true)
        ->assertJsonPath('preferences.show_guest_preferences', false);
});

it('updates the display_recommended_table_assignment preference', function (): void {
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::RestaurantStaff, $this->organization, $this->restaurant);
    Sanctum::actingAs($staff);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'display_recommended_table_assignment' => true,
    ])->assertSuccessful()
        ->assertJsonPath('preferences.display_recommended_table_assignment', true);

    expect(Restaurant::query()->findOrFail($this->restaurant->id)->display_recommended_table_assignment)->toBeTrue();

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences")
        ->assertSuccessful()
        ->assertJsonPath('preferences.display_recommended_table_assignment', true);
});

it('updates display_guest_full_name and show_guest_preferences independently of each other', function (): void {
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::RestaurantStaff, $this->organization, $this->restaurant);
    Sanctum::actingAs($staff);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'display_guest_full_name' => false,
    ])->assertSuccessful()
        ->assertJsonPath('preferences.display_guest_full_name', false)
        ->assertJsonPath('preferences.show_guest_preferences', false);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'show_guest_preferences' => true,
    ])->assertSuccessful()
        ->assertJsonPath('preferences.display_guest_full_name', false)
        ->assertJsonPath('preferences.show_guest_preferences', true);

    $restaurant = Restaurant::query()->findOrFail($this->restaurant->id);
    expect($restaurant->display_guest_full_name)->toBeFalse()
        ->and($restaurant->show_guest_preferences)->toBeTrue();
});

it('validates the preference payload', function (): void {
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::RestaurantStaff, $this->organization, $this->restaurant);
    Sanctum::actingAs($staff);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'display_recommended_table_assignment' => 'not-a-boolean',
        'display_guest_full_name' => 'not-a-boolean',
        'show_guest_preferences' => 'not-a-boolean',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['display_recommended_table_assignment', 'display_guest_full_name', 'show_guest_preferences']);
});

it('forbids a role without tables.manage from updating dashboard preferences', function (): void {
    $analyst = User::factory()->create();
    assignScopedRole($analyst, Role::AnalyticsReporting, $this->organization, $this->restaurant);
    Sanctum::actingAs($analyst);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'display_recommended_table_assignment' => true,
    ])->assertForbidden();
});

it('forbids users outside the restaurant from viewing or updating dashboard preferences', function (): void {
    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences")
        ->assertForbidden();

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/dashboard-preferences", [
        'display_recommended_table_assignment' => true,
    ])->assertForbidden();
});
