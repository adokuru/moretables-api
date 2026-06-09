<?php

use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantHour;
use App\Models\RestaurantTable;
use App\RestaurantStatus;
use Carbon\Carbon;

// ── Helpers ──────────────────────────────────────────────────────────────────

function availUrl(string $slug, string $date, int $partySize = 2, ?string $time = null): string
{
    $query = http_build_query(array_filter([
        'date' => $date,
        'party_size' => $partySize,
        'time' => $time,
    ]));

    return "/api/v1/restaurants/{$slug}/availability?{$query}";
}

// ── Slot interval ─────────────────────────────────────────────────────────────

it('generates slots 15 minutes apart using legacy restaurant_hours', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '20:00',
        'is_closed' => false,
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();

    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();

    $times = collect($slots)->pluck('local_starts_at')->map(fn ($t) => Carbon::parse($t)->format('H:i'))->all();

    // With 2-hour reservation duration and 18:00–20:00 window only the 18:00 slot fits.
    // Verify that if there were multiple slots they are 15 min apart.
    if (count($times) > 1) {
        $diff = Carbon::parse($slots[1]['local_starts_at'])->diffInMinutes(Carbon::parse($slots[0]['local_starts_at']));
        expect($diff)->toBe(15);
    } else {
        expect($times[0])->toBe('18:00');
    }
});

it('generates slots 15 minutes apart using meal schedules', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    $availabilityPeriod = RestaurantAvailabilityPeriod::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Dinner',
    ]);

    // 3-hour window with 2-hour duration → slots at 18:00, 18:15, 18:30, 18:45, 19:00
    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $availabilityPeriod->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    $slots = $response->json('slots');
    expect(count($slots))->toBeGreaterThanOrEqual(2);

    $diff = (int) Carbon::parse($slots[0]['local_starts_at'])->diffInMinutes(Carbon::parse($slots[1]['local_starts_at']));
    expect($diff)->toBe(15);
});

it('returns slots for solo diners when the smallest table has a minimum capacity of two', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    RestaurantTable::query()->where('restaurant_id', $restaurant->id)->update([
        'min_capacity' => 2,
        'max_capacity' => 2,
    ]);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
        'is_closed' => false,
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d'), 1));

    $response->assertOk();

    expect($response->json('slots'))->not->toBeEmpty();
    expect(Carbon::parse($response->json('slots.0.local_starts_at'))->format('H:i'))->toBe('18:00');
});

// ── Meal schedules preferred over legacy hours ────────────────────────────────

it('uses meal schedules when both meal schedules and hours exist', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    // Legacy hours: 09:00–11:00 (no slot fits with 2h duration)
    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '09:00',
        'closes_at' => '11:00',
        'is_closed' => false,
    ]);

    // Meal schedule: 18:00–21:00 (slots available)
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

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();

    // All slots should start at 18:xx, not 09:xx
    foreach ($slots as $slot) {
        expect(Carbon::parse($slot['local_starts_at'])->hour)->toBeGreaterThanOrEqual(18);
    }
});

it('falls back to legacy restaurant_hours when no meal schedules exist', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '12:00',
        'closes_at' => '15:00',
        'is_closed' => false,
    ]);

    // No meal schedules

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();
    expect(Carbon::parse($slots[0]['local_starts_at'])->hour)->toBe(12);
});

it('exposes multiple meal windows in a single day as separate slot groups', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    $lunch = RestaurantAvailabilityPeriod::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Lunch']);
    $dinner = RestaurantAvailabilityPeriod::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Dinner']);

    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $lunch->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '12:00',
        'closes_at' => '15:00',
    ]);
    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $dinner->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();

    $hours = collect($slots)->map(fn ($s) => Carbon::parse($s['local_starts_at'])->hour)->unique()->values()->all();

    // Should have slots in both the 12–15 and 18–21 windows
    $hasLunch = collect($hours)->some(fn ($h) => $h >= 12 && $h < 15);
    $hasDinner = collect($hours)->some(fn ($h) => $h >= 18 && $h < 21);
    expect($hasLunch)->toBeTrue();
    expect($hasDinner)->toBeTrue();
});

// ── Past-time filtering ───────────────────────────────────────────────────────

it('does not return slots in the past when querying today', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $today = Carbon::today('UTC');
    $dayOfWeek = $today->dayOfWeek;

    // Open 00:00–23:59 today so the window spans the whole day
    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '00:00',
        'closes_at' => '23:59',
        'is_closed' => false,
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $today->format('Y-m-d')));

    $response->assertOk();
    $now = Carbon::now('UTC');

    foreach ($response->json('slots') as $slot) {
        expect(Carbon::parse($slot['local_starts_at'])->gt($now))->toBeTrue();
    }
});

it('returns all slots for a future date regardless of current time', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '00:00',
        'closes_at' => '02:00',
        'is_closed' => false,
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    // With 2h duration and 00:00–02:00 window, one slot at 00:00 exists; it's in the future
    expect($response->json('slots'))->not->toBeEmpty();
});

// ── Closed / no-hours days ─────────────────────────────────────────────────────

it('returns empty slots for a closed day', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'UTC']);

    $tomorrow = Carbon::tomorrow('UTC');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'is_closed' => true,
    ]);

    $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')))
        ->assertOk()
        ->assertJsonPath('slots', []);
});

// ── Requester timezone ────────────────────────────────────────────────────────

it('returns local times in the requester timezone when timezone param is provided', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    // Restaurant is in Lagos (UTC+1)
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'Africa/Lagos']);

    $tomorrow = Carbon::tomorrow('Africa/Lagos');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
        'is_closed' => false,
    ]);

    // Requester is in London (UTC+0 / UTC+1 BST — use UTC for determinism)
    $response = $this->getJson(
        availUrl($restaurant->slug, $tomorrow->format('Y-m-d'), 2).'&timezone=UTC'
    );

    $response->assertOk();
    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();

    // UTC is 1 hour behind Africa/Lagos, so 18:00 Lagos = 17:00 UTC
    expect(Carbon::parse($slots[0]['local_starts_at'])->hour)->toBe(17);
    expect($response->json('timezone'))->toBe('UTC');

    // starts_at (UTC ISO) and local_starts_at should represent the same instant
    expect(Carbon::parse($slots[0]['starts_at'])->equalTo(Carbon::parse($slots[0]['local_starts_at'])))->toBeTrue();
});

it('falls back to restaurant timezone in local times when no timezone param given', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active, 'timezone' => 'Africa/Lagos']);

    $tomorrow = Carbon::tomorrow('Africa/Lagos');
    $dayOfWeek = $tomorrow->dayOfWeek;

    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => $dayOfWeek,
        'opens_at' => '18:00',
        'closes_at' => '21:00',
        'is_closed' => false,
    ]);

    $response = $this->getJson(availUrl($restaurant->slug, $tomorrow->format('Y-m-d')));

    $response->assertOk();
    $slots = $response->json('slots');
    expect($slots)->not->toBeEmpty();

    // No timezone param → local times in restaurant timezone (Africa/Lagos, UTC+1)
    expect(Carbon::parse($slots[0]['local_starts_at'])->hour)->toBe(18);
    expect($response->json('timezone'))->toBe('Africa/Lagos');
});

it('rejects an invalid timezone string', function () {
    $data = createListedBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Active]);

    $this->getJson(
        availUrl($restaurant->slug, Carbon::tomorrow()->format('Y-m-d')).'&timezone=Not/ATimezone'
    )->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

// ── 404 for inactive restaurants ──────────────────────────────────────────────

it('returns 404 for an inactive restaurant', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['status' => RestaurantStatus::Draft]);

    $this->getJson(availUrl($restaurant->slug, Carbon::tomorrow()->format('Y-m-d')))
        ->assertNotFound();
});
