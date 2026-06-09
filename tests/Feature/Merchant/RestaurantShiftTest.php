<?php

use App\Models\DiningArea;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed([RoleAndPermissionSeeder::class, BillingPlanSeeder::class]);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create();
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
    activateMerchantBilling($this->restaurant);
});

it('lists weekly shifts for a restaurant', function (): void {
    RestaurantShift::factory()->for($this->restaurant)->create(['name' => 'Lunch', 'day_of_week' => 1]);
    RestaurantShift::factory()->for($this->restaurant)->create(['name' => 'Dinner', 'day_of_week' => 5]);

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts");

    $response->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Lunch')
        ->assertJsonPath('data.1.name', 'Dinner');
});

it('creates a weekly shift with nested settings', function (): void {
    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [
        'name' => 'Friday Dinner',
        'day_of_week' => 5,
        'starts_at' => '17:00',
        'ends_at' => '22:00',
        'max_party_size' => 8,
        'turn_times' => [
            ['party_size' => 2, 'duration_minutes' => 90],
            ['party_size' => 4, 'duration_minutes' => 120],
        ],
        'turn_controls' => [
            ['rule_type' => 'party_size', 'party_size' => 6, 'min_turns' => 2],
        ],
        'flow_controls' => [
            'interval_minutes' => 15,
            'default_max_covers' => 40,
            'intervals' => [
                ['starts_at' => '17:00', 'ends_at' => '19:00', 'max_covers' => 30],
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Friday Dinner')
        ->assertJsonPath('data.day_of_week', 5)
        ->assertJsonCount(2, 'data.turn_times')
        ->assertJsonCount(1, 'data.turn_controls')
        ->assertJsonPath('data.flow_controls.interval_minutes', 15)
        ->assertJsonCount(1, 'data.flow_controls.intervals');

    expect($this->restaurant->shifts()->count())->toBe(1);
});

it('validates required fields when creating a shift', function (): void {
    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'day_of_week', 'starts_at', 'ends_at']);
});

it('shows a single shift', function (): void {
    $shift = RestaurantShift::factory()->for($this->restaurant)->create(['name' => 'Brunch']);

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $shift->id)
        ->assertJsonPath('data.name', 'Brunch');
});

it('updates core shift fields without replacing nested settings when omitted', function (): void {
    $shift = RestaurantShift::factory()
        ->for($this->restaurant)
        ->create(['name' => 'Lunch', 'day_of_week' => 2]);
    $shift->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 90]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}", [
        'name' => 'Late Lunch',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Late Lunch')
        ->assertJsonCount(1, 'data.turn_times');

    expect($shift->fresh()->turnTimes)->toHaveCount(1);
});

it('replaces turn times when turn_times is sent on patch', function (): void {
    $shift = RestaurantShift::factory()->for($this->restaurant)->create();
    $shift->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 60]);
    $shift->turnTimes()->create(['party_size' => 4, 'duration_minutes' => 90]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}", [
        'turn_times' => [
            ['party_size' => 6, 'duration_minutes' => 120],
        ],
    ])
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.turn_times')
        ->assertJsonPath('data.turn_times.0.party_size', 6);

    expect($shift->fresh()->turnTimes)->toHaveCount(1);
});

it('deletes a shift', function (): void {
    $shift = RestaurantShift::factory()->for($this->restaurant)->create();

    deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Shift deleted successfully.');

    expect(RestaurantShift::query()->find($shift->id))->toBeNull();
});

it('returns 404 when shift belongs to another restaurant', function (): void {
    $otherRestaurant = Restaurant::factory()->for($this->organization)->create();
    $shift = RestaurantShift::factory()->for($otherRestaurant)->create();

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}")
        ->assertNotFound();
});

it('forbids viewers from creating shifts', function (): void {
    $viewer = User::factory()->create();
    assignScopedRole($viewer, Role::Operations, $this->organization, $this->restaurant);
    Sanctum::actingAs($viewer);

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [
        'name' => 'Blocked',
        'day_of_week' => 1,
        'starts_at' => '12:00',
        'ends_at' => '15:00',
    ])->assertForbidden();
});

it('creates a shift assigned to all floors via shortcut', function (): void {
    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [
        'name' => 'All Floors Lunch',
        'day_of_week' => 2,
        'starts_at' => '12:00',
        'ends_at' => '15:00',
        'all_floors' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.all_floors', true)
        ->assertJsonPath('data.dining_area_ids', []);

    $shift = RestaurantShift::query()->find($response->json('data.id'));
    expect($shift?->tableAvailability)->toHaveCount(1);
    expect($shift?->tableAvailability->first()?->dining_area_id)->toBeNull();
    expect($shift?->tableAvailability->first()?->table_type)->toBeNull();
});

it('creates a shift assigned to specific dining areas via shortcut', function (): void {
    $mainFloor = DiningArea::factory()->for($this->restaurant)->create(['name' => 'Main']);
    $patio = DiningArea::factory()->for($this->restaurant)->create(['name' => 'Patio']);

    RestaurantTable::factory()->for($this->restaurant)->create([
        'dining_area_id' => $mainFloor->id,
        'max_capacity' => 4,
    ]);
    RestaurantTable::factory()->for($this->restaurant)->create([
        'dining_area_id' => $patio->id,
        'max_capacity' => 6,
    ]);

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [
        'name' => 'Main Only',
        'day_of_week' => 3,
        'starts_at' => '18:00',
        'ends_at' => '22:00',
        'dining_area_ids' => [$mainFloor->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.all_floors', false)
        ->assertJsonPath('data.dining_area_ids', [$mainFloor->id]);

    $shift = RestaurantShift::query()->find($response->json('data.id'));
    expect($shift?->tableAvailability)->not->toBeEmpty();
    expect(
        $shift?->tableAvailability->pluck('dining_area_id')->unique()->values()->all()
    )->toBe([$mainFloor->id]);
});

it('updates floor assignment via shortcut', function (): void {
    $mainFloor = DiningArea::factory()->for($this->restaurant)->create(['name' => 'Main']);
    $patio = DiningArea::factory()->for($this->restaurant)->create(['name' => 'Patio']);

    $shift = RestaurantShift::factory()->for($this->restaurant)->create();

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}", [
        'dining_area_ids' => [$mainFloor->id, $patio->id],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.all_floors', false)
        ->assertJsonCount(2, 'data.dining_area_ids');

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts/{$shift->id}", [
        'all_floors' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.all_floors', true)
        ->assertJsonPath('data.dining_area_ids', []);
});

it('rejects turn controls that reference a table from another restaurant', function (): void {
    $otherRestaurant = createBookableRestaurant();
    $foreignTableId = $otherRestaurant['table']->id;

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/shifts", [
        'name' => 'Dinner',
        'day_of_week' => 4,
        'starts_at' => '18:00',
        'ends_at' => '22:00',
        'turn_controls' => [
            ['rule_type' => 'table', 'restaurant_table_id' => $foreignTableId, 'min_turns' => 1],
        ],
    ])->assertStatus(422);
});
