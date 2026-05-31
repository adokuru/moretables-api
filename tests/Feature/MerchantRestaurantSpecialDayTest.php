<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantSpecialDay;
use App\Models\Role;
use App\Models\User;
use App\Services\RestaurantSpecialDayService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create();
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('creates a special day with custom shifts', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Thanksgiving Day',
        'date' => '2025-11-21',
        'is_closed' => false,
        'shifts' => [
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '07:00', 'closes_at' => '11:00'],
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '12:00', 'closes_at' => '16:00'],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Thanksgiving Day')
        ->assertJsonPath('data.is_closed', false)
        ->assertJsonCount(2, 'data.shifts');

    expect($this->restaurant->specialDays()->count())->toBe(1);
});

it('creates a closed special day without shifts', function (): void {

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Christmas Day',
        'date' => '2025-12-25',
        'is_closed' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.is_closed', true)
        ->assertJsonCount(0, 'data.shifts');
});

it('requires shifts when creating an open special day', function (): void {
    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Open Holiday',
        'date' => '2025-12-24',
        'is_closed' => false,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('shifts');
});

it('ignores shifts when the special day is closed', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Closed Holiday',
        'date' => '2025-12-26',
        'is_closed' => true,
        'shifts' => [
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '07:00', 'closes_at' => '11:00'],
        ],
    ]);

    $response->assertCreated()->assertJsonCount(0, 'data.shifts');
});

it('rejects shifts for a meal type from another restaurant', function (): void {
    $otherMealType = RestaurantAvailabilityPeriod::factory()->create();

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Bad Day',
        'date' => '2025-11-21',
        'shifts' => [
            ['restaurant_meal_type_id' => $otherMealType->id, 'opens_at' => '07:00', 'closes_at' => '11:00'],
        ],
    ]);

    $response->assertStatus(422);
});

it('prevents duplicate special days for the same date', function (): void {
    RestaurantSpecialDay::factory()->for($this->restaurant)->create(['date' => '2025-11-21']);

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Duplicate',
        'date' => '2025-11-21',
        'is_closed' => true,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('date');
});

it('rejects closing times before opening times', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Bad Times',
        'date' => '2025-11-21',
        'shifts' => [
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '17:00', 'closes_at' => '09:00'],
        ],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('shifts.0.closes_at');
});

it('rejects overlapping special day shifts', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'Overlapping Hours',
        'date' => '2025-11-22',
        'shifts' => [
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '12:00', 'closes_at' => '16:00'],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('shifts');
});

it('lists special days for the restaurant', function (): void {
    RestaurantSpecialDay::factory()->for($this->restaurant)->count(2)->create();

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days");

    $response->assertSuccessful()->assertJsonCount(2, 'data');
});

it('updates a special day and replaces its shifts', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->create();
    $specialDay->shifts()->create([
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'opens_at' => '07:00',
        'closes_at' => '11:00',
    ]);

    $response = patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$specialDay->id}", [
        'name' => 'Updated Name',
        'shifts' => [
            ['restaurant_meal_type_id' => $availabilityPeriod->id, 'opens_at' => '18:00', 'closes_at' => '22:00'],
        ],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonCount(1, 'data.shifts')
        ->assertJsonPath('data.shifts.0.opens_at', '18:00');

    expect($specialDay->shifts()->count())->toBe(1);
});

it('clears shifts when a special day is updated to closed', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->create();
    $specialDay->shifts()->create([
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'opens_at' => '07:00',
        'closes_at' => '11:00',
    ]);

    $response = patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$specialDay->id}", [
        'is_closed' => true,
    ]);

    $response->assertSuccessful()->assertJsonPath('data.is_closed', true);
    expect($specialDay->shifts()->count())->toBe(0);
});

it('preserves shifts when an open special day receives an is_closed false patch', function (): void {
    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->create();
    $specialDay->shifts()->create([
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'opens_at' => '07:00',
        'closes_at' => '11:00',
    ]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$specialDay->id}", [
        'is_closed' => false,
    ])->assertSuccessful()
        ->assertJsonCount(1, 'data.shifts');
});

it('requires shifts when reopening a closed special day', function (): void {
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->closed()->create();

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$specialDay->id}", [
        'is_closed' => false,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('shifts');
});

it('translates duplicate date database exceptions into validation exceptions', function (): void {
    RestaurantSpecialDay::factory()->for($this->restaurant)->closed()->create(['date' => '2025-11-23']);

    expect(fn () => app(RestaurantSpecialDayService::class)->create($this->restaurant, [
        'name' => 'Duplicate',
        'date' => '2025-11-23',
        'is_closed' => true,
    ]))->toThrow(ValidationException::class, 'A special day already exists for this date.');
});

it('rejects direct open special day creation without shifts', function (): void {
    expect(fn () => app(RestaurantSpecialDayService::class)->create($this->restaurant, [
        'name' => 'Missing Shifts',
        'date' => '2025-11-24',
        'is_closed' => false,
    ]))->toThrow(ValidationException::class, 'At least one shift is required when the special day is open.');
});

it('normalizes legacy duplicate special day dates by keeping the latest override', function (): void {
    $olderId = DB::table('restaurant_special_days')->insertGetId([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Older Override',
        'date' => '2025-11-25 00:00:00',
        'is_closed' => true,
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $newerId = DB::table('restaurant_special_days')->insertGetId([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Latest Override',
        'date' => '2025-11-25',
        'is_closed' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_05_31_210937_normalize_restaurant_special_day_dates.php');
    $migration->up();

    expect(DB::table('restaurant_special_days')->where('id', $olderId)->exists())->toBeFalse()
        ->and(DB::table('restaurant_special_days')->where('id', $newerId)->value('date'))->toBe('2025-11-25');
});

it('deletes a special day', function (): void {
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->create();

    $response = deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$specialDay->id}");

    $response->assertSuccessful();
    expect(RestaurantSpecialDay::query()->whereKey($specialDay->id)->exists())->toBeFalse();
});

it('returns 404 when the special day belongs to another restaurant', function (): void {
    $otherSpecialDay = RestaurantSpecialDay::factory()->create();

    $response = getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days/{$otherSpecialDay->id}");

    $response->assertNotFound();
});

it('forbids managing special days without permission', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/special-days", [
        'name' => 'No Access',
        'date' => '2025-11-21',
        'is_closed' => true,
    ]);

    $response->assertForbidden();
});
