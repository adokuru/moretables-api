<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create([
        'rewards_enabled' => true,
        'reservation_reward_points' => 100,
    ]);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('reports that a restaurant offers credits with the default score', function (): void {
    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.restaurant_id', $this->restaurant->id)
        ->assertJsonPath('rewards.offers_credits', true)
        ->assertJsonPath('rewards.has_custom_score', false)
        ->assertJsonPath('rewards.reservation_reward_points', 100)
        ->assertJsonPath('rewards.default_reward_points', 100);
});

it('flags when the restaurant has entered a custom score', function (): void {
    $this->restaurant->update(['reservation_reward_points' => 250]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', true)
        ->assertJsonPath('rewards.has_custom_score', true)
        ->assertJsonPath('rewards.reservation_reward_points', 250);
});

it('reports when a restaurant does not offer credits', function (): void {
    $this->restaurant->update(['rewards_enabled' => false]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/rewards/status")
        ->assertOk()
        ->assertJsonPath('rewards.offers_credits', false);
});

it('prevents checking the reward status of another restaurant', function (): void {
    $otherRestaurant = Restaurant::factory()->create();

    getJson("/api/v1/merchant/restaurants/{$otherRestaurant->id}/rewards/status")
        ->assertForbidden();
});
