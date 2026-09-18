<?php

use App\Models\Reservation;
use App\Models\Restaurant;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A reviewer opening either app must see a populated board. These assert
 * through the same front-of-house endpoints the apps call, not row counts.
 */
beforeEach(function () {
    Notification::fake();

    config([
        'auth.demo.code' => '5678',
        'auth.demo.customer.email' => 'demo@moretables.com',
        'auth.demo.restaurant.email' => 'demo-restaurant@moretables.com',
        'auth.demo.restaurant.password' => 'MoreTablesDemo1!',
        'auth.demo.restaurant.name' => 'MoreTables Demo Kitchen',
        'auth.demo.emails' => ['demo@moretables.com', 'demo-restaurant@moretables.com'],
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
    $this->artisan('app:provision-demo')->assertSuccessful();
});

function demoToken(): string
{
    $challenge = test()->postJson('/api/v1/auth/staff/login', [
        'identifier' => 'demo-restaurant@moretables.com',
        'password' => 'MoreTablesDemo1!',
    ])->assertOk()->json('challenge_token');

    return test()->postJson('/api/v1/auth/staff/verify-2fa', [
        'challenge_token' => $challenge,
        'code' => '5678',
    ])->assertOk()->json('token');
}

it('fills every board column the reviewer can open', function () {
    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();

    $restaurant = Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail();
    $base = "/api/v1/merchant/restaurants/{$restaurant->id}/front-of-house";
    $token = demoToken();
    $date = now($restaurant->timezone ?: config('app.timezone'))->toDateString();

    foreach (['waitlist', 'reservations', 'seated', 'finished', 'removed'] as $bucket) {
        $rows = $this->withToken($token)->getJson("{$base}/{$bucket}?date={$date}")->assertOk()->json('data');

        expect($rows)->not->toBeEmpty("the {$bucket} column is empty");
    }
});

it('dates the bookings today, so the board is never stale', function () {
    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();

    $restaurant = Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail();
    $today = now($restaurant->timezone ?: config('app.timezone'))->toDateString();

    $dates = Reservation::query()
        ->where('restaurant_id', $restaurant->id)
        ->get()
        ->map(fn (Reservation $r): string => $r->starts_at->setTimezone($restaurant->timezone ?: config('app.timezone'))->toDateString())
        ->unique();

    expect($dates->all())->toBe([$today]);
});

it('replaces its own bookings but never a reviewer\'s', function () {
    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();

    $restaurant = Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail();
    $seeded = Reservation::query()->where('restaurant_id', $restaurant->id)->count();

    // Something a reviewer booked by hand: no demo_seed marker.
    $reviewerBooking = Reservation::query()->create([
        'restaurant_id' => $restaurant->id,
        'reservation_reference' => 'MT-REVIEWER',
        'status' => App\ReservationStatus::Booked->value,
        'party_size' => 2,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);

    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();

    expect(Reservation::query()->whereKey($reviewerBooking->id)->exists())->toBeTrue()
        ->and(Reservation::query()->where('restaurant_id', $restaurant->id)->count())->toBe($seeded + 1);
});

it('gives the customer-app reviewer their own booking history', function () {
    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();

    $challenge = $this->postJson('/api/v1/auth/start', ['email' => 'demo@moretables.com'])
        ->assertCreated()->json('challenge_token');

    $token = $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $challenge,
        'code' => '5678',
    ])->assertOk()->json('token');

    $body = $this->withToken($token)->getJson('/api/v1/me/reservations')->assertOk();

    // Paginated resource collections serialize without a top-level data key here.
    expect($body->getContent())->toContain('moretables-demo-kitchen');
});

it('does nothing when the demo restaurant has not been provisioned', function () {
    Restaurant::query()->where('slug', 'moretables-demo-kitchen')->delete();

    $this->artisan('app:refresh-demo-reservations')->assertSuccessful();
});
