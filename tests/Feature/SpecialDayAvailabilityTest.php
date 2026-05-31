<?php

use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantSpecialDay;
use App\Models\RestaurantTable;
use App\Services\AvailabilityService;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->service = app(AvailabilityService::class);
    $this->restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);
    RestaurantTable::factory()->for($this->restaurant)->create([
        'min_capacity' => 1,
        'max_capacity' => 8,
    ]);

    $this->availabilityPeriod = RestaurantAvailabilityPeriod::factory()->for($this->restaurant)->create();
    $this->date = Carbon::now('UTC')->addMonth()->startOfMonth()->next(Carbon::MONDAY);

    $this->restaurant->availabilitySchedules()->create([
        'restaurant_meal_type_id' => $this->availabilityPeriod->id,
        'day_of_week' => (int) $this->date->dayOfWeek,
        'opens_at' => '09:00',
        'closes_at' => '17:00',
    ]);
});

it('returns no slots on a closed special day', function (): void {
    RestaurantSpecialDay::factory()->for($this->restaurant)->closed()->create([
        'date' => $this->date->format('Y-m-d'),
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $this->restaurant->fresh(),
        date: $this->date->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->toBeEmpty();
});

it('uses special day shifts instead of the weekly schedule', function (): void {
    $specialDay = RestaurantSpecialDay::factory()->for($this->restaurant)->create([
        'date' => $this->date->format('Y-m-d'),
    ]);
    $specialDay->shifts()->create([
        'restaurant_meal_type_id' => $this->availabilityPeriod->id,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    $slots = $this->service->listAvailableSlots(
        restaurant: $this->restaurant->fresh(),
        date: $this->date->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->not->toBeEmpty();

    foreach ($slots as $slot) {
        $hour = (int) Carbon::parse($slot['local_starts_at'])->format('H');
        expect($hour)->toBeGreaterThanOrEqual(18)->toBeLessThan(21);
    }
});

it('falls back to the weekly schedule when no special day exists', function (): void {
    $slots = $this->service->listAvailableSlots(
        restaurant: $this->restaurant->fresh(),
        date: $this->date->format('Y-m-d'),
        partySize: 2,
    );

    expect($slots)->not->toBeEmpty();

    $firstHour = (int) Carbon::parse($slots[0]['local_starts_at'])->format('H');
    expect($firstHour)->toBeGreaterThanOrEqual(9)->toBeLessThan(17);
});
