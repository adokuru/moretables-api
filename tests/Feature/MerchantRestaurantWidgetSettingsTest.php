<?php

use App\BillingPlanSlug;
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
    // Reservation Widget is Core/Premium-only (docs/PLAN_PERMISSIONS.md) — this file's
    // existing tests predate that gate and expect saving to just work, so default to
    // Premium. The plan-gating tests further down explicitly downgrade instead.
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Premium);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('shows default widget settings when none are stored', function (): void {
    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings");

    $response->assertSuccessful()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.theme', 'light')
        ->assertJsonPath('data.accent_color', '#AF8C59')
        ->assertJsonPath('data.layout', 'card')
        ->assertJsonPath('data.button_text', 'Book a table');
});

it('updates widget settings and merges partial updates over stored values', function (): void {
    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'dark',
        'accent_color' => '#A52700',
    ])->assertSuccessful()
        ->assertJsonPath('data.theme', 'dark')
        ->assertJsonPath('data.accent_color', '#A52700')
        ->assertJsonPath('data.layout', 'card');

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'enabled' => false,
        'layout' => 'button',
        'button_text' => 'Reserve now',
    ])->assertSuccessful()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.theme', 'dark')
        ->assertJsonPath('data.layout', 'button')
        ->assertJsonPath('data.button_text', 'Reserve now');

    $settings = Restaurant::query()->findOrFail($this->restaurant->id)->widgetSettingsWithDefaults();
    expect($settings['theme'])->toBe('dark')
        ->and($settings['enabled'])->toBeFalse()
        ->and($settings['button_text'])->toBe('Reserve now');
});

it('validates widget settings values', function (): void {
    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'neon',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['theme']);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'accent_color' => 'red',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['accent_color']);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'layout' => 'banner',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['layout']);
});

it('exposes widget settings on the public restaurant detail endpoint', function (): void {
    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'dark',
    ])->assertSuccessful();

    getJson("/api/v1/restaurants/{$this->restaurant->slug}")
        ->assertSuccessful()
        ->assertJsonPath('data.widget_settings.theme', 'dark')
        ->assertJsonPath('data.widget_settings.enabled', true);
});

it('forbids users without restaurant manage permission from updating widget settings', function (): void {
    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'dark',
    ])->assertForbidden();
});

// Plan-tier gating — Reservation Widget is Core/Premium-only (docs/PLAN_PERMISSIONS.md).
// $this->restaurant is Premium by default (see beforeEach); these tests explicitly
// downgrade it.

it('rejects updating widget settings for a restaurant below Core with an upgrade message', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'dark',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Core or Premium to configure your reservation widget.');

    $settings = Restaurant::query()->findOrFail($this->restaurant->id)->widgetSettingsWithDefaults();
    expect($settings['theme'])->toBe('light');
});

it('allows updating widget settings on the Core plan (not just Premium)', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Core);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings", [
        'theme' => 'dark',
    ])->assertSuccessful()->assertJsonPath('data.theme', 'dark');
});

it('still shows widget settings and the public embed for a restaurant below Core', function (): void {
    setRestaurantBillingPlan($this->restaurant, BillingPlanSlug::Foundation);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/widget-settings")
        ->assertSuccessful()
        ->assertJsonPath('data.enabled', true);

    getJson("/api/v1/restaurants/{$this->restaurant->slug}")
        ->assertSuccessful()
        ->assertJsonPath('data.widget_settings.enabled', true);
});
