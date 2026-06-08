<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantShift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\artisan;
use function Pest\Laravel\putJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create();
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('seeds weekly shifts when onboarding business hours are saved for the first time', function (): void {
    $period = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create(['name' => 'Lunch']);

    putJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/onboarding/business-hours", [
        'schedules' => [
            [
                'restaurant_meal_type_id' => $period->id,
                'day_of_week' => 1,
                'opens_at' => '11:30',
                'closes_at' => '15:00',
            ],
            [
                'restaurant_meal_type_id' => $period->id,
                'day_of_week' => 5,
                'opens_at' => '17:00',
                'closes_at' => '22:00',
            ],
        ],
    ])->assertSuccessful();

    $shifts = $this->restaurant->fresh()->shifts()->orderBy('day_of_week')->get();

    expect($shifts)->toHaveCount(2)
        ->and($shifts->pluck('day_of_week')->all())->toBe([1, 5])
        ->and($shifts->first()->turnTimes)->not->toBeEmpty();
});

it('does not re-seed shifts when business hours are updated after shifts exist', function (): void {
    $period = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $this->restaurant->id,
        'restaurant_meal_type_id' => $period->id,
        'day_of_week' => 2,
        'opens_at' => '12:00',
        'closes_at' => '14:00',
    ]);

    RestaurantShift::factory()->for($this->restaurant)->create([
        'name' => 'Existing',
        'day_of_week' => 2,
        'starts_at' => '12:00:00',
        'ends_at' => '14:00:00',
    ]);

    putJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/onboarding/business-hours", [
        'schedules' => [
            [
                'restaurant_meal_type_id' => $period->id,
                'day_of_week' => 3,
                'opens_at' => '18:00',
                'closes_at' => '22:00',
            ],
        ],
    ])->assertSuccessful();

    expect($this->restaurant->fresh()->shifts()->count())->toBe(1);
});

it('backfills shifts from schedules for restaurants without shifts', function (): void {
    $period = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $this->restaurant->id,
        'restaurant_meal_type_id' => $period->id,
        'day_of_week' => 0,
        'opens_at' => '10:00',
        'closes_at' => '14:00',
    ]);

    artisan('shifts:backfill-from-schedules')
        ->assertSuccessful();

    expect($this->restaurant->fresh()->shifts()->count())->toBe(1);
});

it('skips restaurants that already have shifts during backfill', function (): void {
    RestaurantShift::factory()->for($this->restaurant)->create();
    $period = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $this->restaurant->id,
        'restaurant_meal_type_id' => $period->id,
        'day_of_week' => 4,
        'opens_at' => '17:00',
        'closes_at' => '21:00',
    ]);

    artisan('shifts:backfill-from-schedules')
        ->assertSuccessful();

    expect($this->restaurant->fresh()->shifts()->count())->toBe(1);
});
