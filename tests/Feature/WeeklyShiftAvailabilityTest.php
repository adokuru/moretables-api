<?php

use App\Enums\RestaurantShiftTurnControlReleasePolicy;
use App\Enums\RestaurantShiftTurnControlRuleType;
use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantHour;
use App\Models\RestaurantShift;
use App\Models\RestaurantShiftTableAvailability;
use App\Models\RestaurantShiftTurnControl;
use App\Models\RestaurantSpecialDay;
use App\Models\RestaurantTable;
use App\RestaurantStatus;
use App\Services\AvailabilityService;
use App\Services\RestaurantShiftService;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->service = app(AvailabilityService::class);
});

it('uses weekly shifts instead of meal schedules and legacy hours when any weekly shifts exist', function (): void {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '09:00',
        'closes_at' => '11:00',
        'is_closed' => false,
    ]);

    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Dinner',
    ]);
    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'starts_at' => '19:00',
        'ends_at' => '21:00',
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $restaurant->fresh(),
        date: $tomorrow->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->not->toBeEmpty();
    expect(Carbon::parse($slots[0]['local_starts_at'])->format('H:i'))->toBe('19:00');

    foreach ($slots as $slot) {
        $hour = (int) Carbon::parse($slot['local_starts_at'])->format('H');
        expect($hour)->toBeGreaterThanOrEqual(19)->toBeLessThan(21);
    }
});

it('returns no slots when weekly shifts exist but none match the requested day', function (): void {
    $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);
    RestaurantTable::factory()->for($restaurant)->create([
        'min_capacity' => 1,
        'max_capacity' => 8,
    ]);

    $date = Carbon::now('UTC')->addMonth()->startOfMonth()->next(Carbon::MONDAY);

    RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => ($date->dayOfWeek + 1) % 7,
        'starts_at' => '09:00',
        'ends_at' => '17:00',
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $restaurant->fresh(),
        date: $date->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->toBeEmpty();
});

it('uses special day shifts instead of weekly shifts', function (): void {
    $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);
    RestaurantTable::factory()->for($restaurant)->create([
        'min_capacity' => 1,
        'max_capacity' => 8,
    ]);

    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($restaurant)->create();
    $date = Carbon::now('UTC')->addMonth()->startOfMonth()->next(Carbon::MONDAY);

    RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => (int) $date->dayOfWeek,
        'starts_at' => '09:00',
        'ends_at' => '17:00',
    ]);

    $specialDay = RestaurantSpecialDay::factory()->for($restaurant)->create([
        'date' => $date->format('Y-m-d'),
    ]);
    $specialDay->shifts()->create([
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $restaurant->fresh(),
        date: $date->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->not->toBeEmpty();

    foreach ($slots as $slot) {
        $hour = (int) Carbon::parse($slot['local_starts_at'])->format('H');
        expect($hour)->toBeGreaterThanOrEqual(18)->toBeLessThan(21);
    }
});

it('returns no slots when a weekly shift marks all tables as not reservable', function (): void {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');

    $shift = RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $tomorrow->dayOfWeek,
        'starts_at' => '12:00',
        'ends_at' => '22:00',
    ]);

    RestaurantShiftTableAvailability::factory()->create([
        'restaurant_shift_id' => $shift->id,
        'dining_area_id' => null,
        'table_type' => null,
        'is_reservable' => false,
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $restaurant->fresh(),
        date: $tomorrow->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->toBeEmpty();
});

it('does not silently block a larger party when shifts are auto-seeded from availability schedules on onboarding', function (): void {
    // Regression test — RestaurantShift::flow_default_max_covers used to
    // default to a hardcoded 3 (both at the DB column level and in
    // RestaurantShiftService), which meant a party of 4+ was rejected at
    // every single slot on an otherwise completely empty day, even when the
    // restaurant's own tables could easily seat them. seedFromSchedules() —
    // the actual path real restaurants' shifts get created through on
    // onboarding — now derives the default from the restaurant's table
    // capacity instead.
    $restaurant = Restaurant::factory()->create(['timezone' => 'UTC', 'total_seating_capacity' => null]);
    RestaurantTable::factory()->for($restaurant)->create(['min_capacity' => 1, 'max_capacity' => 8]);
    RestaurantTable::factory()->for($restaurant)->create(['min_capacity' => 1, 'max_capacity' => 8]);

    $tomorrow = Carbon::tomorrow('UTC');

    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Dinner',
    ]);
    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'day_of_week' => $tomorrow->dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    app(RestaurantShiftService::class)->seedFromSchedules($restaurant);

    $shift = RestaurantShift::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
    expect($shift->flow_default_max_covers)->toBe(16);

    $slots = $this->service->listAvailableSlots(
        restaurant: $restaurant->fresh(),
        date: $tomorrow->format('Y-m-d'),
        partySize: 4,
    );

    expect($slots)->not->toBeEmpty();
});

it('resolves overnight shifts and preserves duration and table release after midnight', function (): void {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['timezone' => 'Africa/Lagos']);
    $date = Carbon::tomorrow('Africa/Lagos');
    $shift = RestaurantShift::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $date->dayOfWeek,
        'starts_at' => '18:00:00',
        'ends_at' => '04:00:00',
        'turn_control_release_policy' => RestaurantShiftTurnControlReleasePolicy::AtShiftStart,
    ]);
    $shift->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 60]);
    RestaurantShiftTurnControl::factory()->create(['restaurant_shift_id' => $shift->id, 'rule_type' => RestaurantShiftTurnControlRuleType::Table, 'restaurant_table_id' => $data['table']->id]);
    $shift->flowIntervals()->create(['starts_at' => '23:00:00', 'max_covers' => 3]);
    $slot = $date->copy()->addDay()->setTime(1, 0);
    $shifts = app(RestaurantShiftService::class);
    expect($shifts->resolveShiftForSlot($restaurant, $date->copy()->setTime(23, 0))?->id)->toBe($shift->id)
        ->and($shifts->resolveShiftForSlot($restaurant, $slot)?->id)->toBe($shift->id)
        ->and($shifts->maxCoversForInterval($shift->fresh(['flowIntervals']), $slot))->toBe(3)
        ->and($shifts->isTableReleased($shift->fresh(['turnControls']), $data['table'], $slot))->toBeTrue()
        ->and($this->service->isBookableAt($restaurant, $slot, 2))->toBeTrue()
        ->and($this->service->calculateEndTime($restaurant, $slot, 2)->format('H:i'))->toBe('02:00')
        ->and($this->service->isBookableAt($restaurant, $slot->copy()->setTime(3, 30), 2))->toBeFalse()
        ->and($shifts->resolveShiftForSlot($restaurant, $slot->copy()->setTime(4, 0)))->toBeNull();
});

it('honours overnight hours without overriding a closed special day', function (): void {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['timezone' => 'Africa/Lagos']);
    $restaurant->hours()->update(['opens_at' => '18:00:00', 'closes_at' => '06:00:00']);
    $slot = Carbon::tomorrow('Africa/Lagos')->setTime(2, 0);
    expect($this->service->isBookableAt($restaurant, $slot))->toBeTrue()
        ->and($this->service->isBookableAt($restaurant, $slot->copy()->setTime(5, 0)))->toBeFalse();
    RestaurantSpecialDay::factory()->for($restaurant)->closed()->create(['date' => $slot->toDateString()]);
    expect($this->service->isBookableAt($restaurant->fresh(), $slot))->toBeFalse();
});
