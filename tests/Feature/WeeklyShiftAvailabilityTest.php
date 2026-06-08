<?php

use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantHour;
use App\Models\RestaurantShift;
use App\Models\RestaurantShiftTableAvailability;
use App\Models\RestaurantSpecialDay;
use App\Models\RestaurantTable;
use App\RestaurantStatus;
use App\Services\AvailabilityService;
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
