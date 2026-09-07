<?php

use App\BillingPlanSlug;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\User;
use App\ReservationStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'UTC'));
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->a = Restaurant::factory()->create(['organization_id' => $this->organization->id, 'name' => 'A', 'timezone' => 'Africa/Lagos']);
    $this->b = Restaurant::factory()->create(['organization_id' => $this->organization->id, 'name' => 'B', 'timezone' => 'Africa/Lagos']);
    $this->subscription = activateBusinessBilling($this->organization);
    $this->viewer = User::factory()->create();
    assignScopedRole($this->viewer, Role::AnalyticsReporting, $this->organization);
    Sanctum::actingAs($this->viewer);
    $this->base = '/api/v1/merchant/businesses/'.$this->organization->id.'/reporting/group';
});

it('returns real business rows, weighted benchmarks, selected comparisons and null spend', function (): void {
    foreach ([[$this->a, 10], [$this->a, 20], [$this->b, 10]] as [$restaurant, $size]) {
        Reservation::factory()->create(['restaurant_id' => $restaurant->id, 'party_size' => $size, 'status' => ReservationStatus::Completed, 'starts_at' => '2026-09-07 12:00:00']);
    }
    Reservation::factory()->create(['restaurant_id' => $this->a->id, 'party_size' => 50, 'status' => ReservationStatus::Cancelled, 'starts_at' => '2026-09-07 12:00:00']);
    foreach ([[$this->a, 4], [$this->a, 5], [$this->b, 3]] as [$restaurant, $rating]) {
        RestaurantReview::factory()->create(['restaurant_id' => $restaurant->id, 'rating' => $rating, 'visited_at' => '2026-09-06']);
    }
    RestaurantReview::factory()->create(['restaurant_id' => $this->a->id, 'rating' => 1, 'visited_at' => '2026-08-01']);
    $response = $this->getJson($this->base.'?period=this_month')->assertOk()->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.seatedCovers', 30)->assertJsonPath('data.0.overallRating', 4.5)
        ->assertJsonPath('data.0.guestSpend', null)->assertJsonPath('data.0.perCover', null);
    expect((float) $response->json('data.0.overallRatingChange'))->toBe(12.5)
        ->and((float) $response->json('data.0.seatedCoversChange'))->toBe(50.0);
    $response = $this->getJson($this->base.'?restaurant_id='.$this->a->id.'&compare_restaurant_id='.$this->b->id)->assertOk()->assertJsonCount(1, 'data');
    expect((float) $response->json('data.0.overallRatingChange'))->toBe(50.0)
        ->and((float) $response->json('data.0.seatedCoversChange'))->toBe(200.0);
    $this->getJson($this->base.'?period=last_month')->assertOk()->assertJsonPath('data.0.seatedCovers', 0);
    $session = $this->getJson('/api/v1/merchant/restaurants')->assertOk();
    $session->assertJsonPath('restaurants.0.group_reporting.can_view', true)->assertJsonPath('restaurants.0.group_reporting.can_export', false);
});

it('returns unavailable changes for missing or zero comparison data', function (): void {
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.0.overallRating', null)
        ->assertJsonPath('data.0.overallRatingChange', null)->assertJsonPath('data.0.seatedCoversChange', null);
});

it('requires business Premium rather than a restaurant Premium subscription', function (string $plan): void {
    $this->subscription->delete();
    activateBusinessBilling($this->organization, $plan);
    activateMerchantBilling($this->a);
    setRestaurantBillingPlan($this->a, BillingPlanSlug::Premium);
    $this->getJson($this->base)->assertForbidden();
})->with(['foundation', 'core']);

it('rejects expired businesses and businesses with one restaurant', function (): void {
    $this->subscription->update(['current_period_end' => now()->subSecond()]);
    $this->getJson($this->base)->assertForbidden();
    $this->subscription->update(['current_period_end' => now()->addMonth()]);
    $this->b->delete();
    $this->getJson($this->base)->assertForbidden();
});

it('does not grant group access to restaurant-only staff or other businesses', function (): void {
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::AnalyticsReporting, $this->organization, $this->a);
    Sanctum::actingAs($staff);
    $this->getJson($this->base)->assertForbidden();
    $this->getJson('/api/v1/merchant/restaurants')->assertOk()->assertJsonPath('restaurants.0.group_reporting.can_view', false);
    Sanctum::actingAs($this->viewer);
    $other = createBookableRestaurant();
    $this->getJson($this->base.'?restaurant_id='.$other['restaurant']->id)->assertUnprocessable();
    $this->getJson($this->base.'?compare_restaurant_id='.$other['restaurant']->id)->assertUnprocessable();
    $this->getJson('/api/v1/merchant/businesses/'.$other['organization']->id.'/reporting/group')->assertForbidden();
});

it('separately authorizes CSV exports and exports all selected rows', function (): void {
    $this->getJson($this->base.'/export')->assertForbidden();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $this->organization);
    Sanctum::actingAs($owner);
    $csv = $this->get($this->base.'/export')->assertOk()->streamedContent();
    expect($csv)->toContain('Restaurant', '—');
    expect(count(array_filter(explode("\n", trim($csv)))))->toBe(3);
    $csv = $this->get($this->base.'/export?restaurant_id='.$this->a->id)->assertOk()->streamedContent();
    expect(count(array_filter(explode("\n", trim($csv)))))->toBe(2);
});
