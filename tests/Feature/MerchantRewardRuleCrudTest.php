<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantRewardRule;
use App\Models\Role;
use App\Models\User;
use App\Services\RestaurantRewardRuleService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create([
        'timezone' => 'UTC',
        'reservation_reward_points' => 100,
    ]);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('creates a reward rule for days and times', function (): void {
    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules", [
        'points' => 300,
        'days' => [1, 2],
        'times' => ['09:00', '09:15'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.points', 300)
        ->assertJsonPath('data.days', [1, 2])
        ->assertJsonPath('data.times', ['09:00', '09:15'])
        ->assertJsonPath('data.applies_all_day', false)
        ->assertJsonPath('data.day_labels', ['Mondays', 'Tuesdays'])
        ->assertJsonPath('data.time_labels', ['9:00 AM', '9:15 AM']);

    expect($this->restaurant->rewardRules()->count())->toBe(1);
});

it('creates a day-only rule that applies all day', function (): void {
    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules", [
        'points' => 200,
        'days' => [5],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.applies_all_day', true)
        ->assertJsonPath('data.times', []);
});

it('lists, updates and deletes reward rules', function (): void {
    $rule = RestaurantRewardRule::factory()->for($this->restaurant)->create(['points' => 150, 'days' => [3]]);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules/{$rule->id}", [
        'points' => 250,
    ])->assertOk()->assertJsonPath('data.points', 250);

    deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Reward rule deleted successfully.');

    expect($this->restaurant->rewardRules()->count())->toBe(0);
});

it('validates points and day ranges', function (): void {
    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules", [
        'days' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors(['points', 'days']);

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules", [
        'points' => 100,
        'days' => [9],
        'times' => ['9am'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['days.0', 'times.0']);
});

it('prevents managing reward rules from another restaurant', function (): void {
    $otherRule = RestaurantRewardRule::factory()->create();

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/reward-rules/{$otherRule->id}")
        ->assertNotFound();
});

describe('points resolution', function (): void {
    it('falls back to the restaurant default when no rule matches', function (): void {
        $points = app(RestaurantRewardRuleService::class)
            ->resolvePoints($this->restaurant, Carbon::parse('2026-06-01 09:00', 'UTC'));

        expect($points)->toBe(100);
    });

    it('matches a day-and-time rule', function (): void {
        // 2026-06-01 is a Monday
        RestaurantRewardRule::factory()->for($this->restaurant)
            ->withTimes(['09:00'])->create(['points' => 300, 'days' => [1]]);

        $points = app(RestaurantRewardRuleService::class)
            ->resolvePoints($this->restaurant->fresh(), Carbon::parse('2026-06-01 09:00', 'UTC'));

        expect($points)->toBe(300);
    });

    it('prefers the time-specific rule over a day-only rule', function (): void {
        RestaurantRewardRule::factory()->for($this->restaurant)->create(['points' => 200, 'days' => [1]]);
        RestaurantRewardRule::factory()->for($this->restaurant)
            ->withTimes(['09:00'])->create(['points' => 300, 'days' => [1]]);

        $points = app(RestaurantRewardRuleService::class)
            ->resolvePoints($this->restaurant->fresh(), Carbon::parse('2026-06-01 09:00', 'UTC'));

        expect($points)->toBe(300);
    });

    it('uses the highest points when specificity ties', function (): void {
        RestaurantRewardRule::factory()->for($this->restaurant)->create(['points' => 200, 'days' => [1]]);
        RestaurantRewardRule::factory()->for($this->restaurant)->create(['points' => 250, 'days' => [1]]);

        $points = app(RestaurantRewardRuleService::class)
            ->resolvePoints($this->restaurant->fresh(), Carbon::parse('2026-06-01 12:30', 'UTC'));

        expect($points)->toBe(250);
    });

    it('ignores inactive rules', function (): void {
        RestaurantRewardRule::factory()->for($this->restaurant)->inactive()
            ->create(['points' => 500, 'days' => [1]]);

        $points = app(RestaurantRewardRuleService::class)
            ->resolvePoints($this->restaurant->fresh(), Carbon::parse('2026-06-01 12:30', 'UTC'));

        expect($points)->toBe(100);
    });
});
