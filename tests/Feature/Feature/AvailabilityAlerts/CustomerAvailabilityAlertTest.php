<?php

use App\Jobs\ProcessRestaurantAvailabilityAlerts;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\AvailabilityAlertNotification;
use App\Notifications\ExpoPushChannel;
use App\Services\AvailabilityAlertService;
use App\Services\ReservationService;
use App\WaitlistStatus;
use App\WaitlistType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function availabilityAlertWindow(string $timezone = 'Africa/Lagos'): array
{
    $start = Carbon::tomorrow($timezone)->setTime(12, 0);
    $end = Carbon::tomorrow($timezone)->setTime(14, 0);

    return [
        'start_utc' => $start->copy()->utc(),
        'end_utc' => $end->copy()->utc(),
        'date' => $start->toDateString(),
    ];
}

it('lets a customer create a table availability alert', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $window = availabilityAlertWindow();

    $response = $this->postJson('/api/v1/restaurants/'.$data['restaurant']->slug.'/availability-alerts', [
        'party_size' => 2,
        'date' => $window['date'],
        'window_start' => '12:00',
        'window_end' => '14:00',
        'whatsapp_updates' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('availability_alert.party_size', 2)
        ->assertJsonPath('availability_alert.whatsapp_updates', true)
        ->assertJsonPath('availability_alert.status', WaitlistStatus::Waiting->value);

    $this->assertDatabaseHas('waitlist_entries', [
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'type' => WaitlistType::AvailabilityAlert->value,
        'party_size' => 2,
        'whatsapp_updates' => true,
    ]);
});

it('validates availability alert input', function (array $payload, string $field) {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $this->postJson('/api/v1/restaurants/'.$data['restaurant']->slug.'/availability-alerts', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'past date' => [['party_size' => 2, 'date' => '2000-01-01', 'window_start' => '12:00', 'window_end' => '14:00'], 'date'],
    'end before start' => [['party_size' => 2, 'date' => '2999-01-01', 'window_start' => '14:00', 'window_end' => '12:00'], 'window_end'],
    'missing party size' => [['date' => '2999-01-01', 'window_start' => '12:00', 'window_end' => '14:00'], 'party_size'],
]);

it('returns an existing active alert instead of duplicating it', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $window = availabilityAlertWindow();
    $payload = [
        'party_size' => 2,
        'date' => $window['date'],
        'window_start' => '12:00',
        'window_end' => '14:00',
    ];

    $this->postJson('/api/v1/restaurants/'.$data['restaurant']->slug.'/availability-alerts', $payload)->assertCreated();
    $this->postJson('/api/v1/restaurants/'.$data['restaurant']->slug.'/availability-alerts', $payload)->assertOk();

    expect(WaitlistEntry::query()->availabilityAlerts()->count())->toBe(1);
});

it('lists only the customer own availability alerts and excludes seating waitlist entries', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
    ]);
    WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
    ]);

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/availability-alerts')->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($customer->waitlistEntries()->availabilityAlerts()->first()->id);
});

it('points the availability alert book button at the frontend restaurant page', function () {
    config(['app.url' => 'https://api.moretables.test', 'app.frontend_urls.main' => 'https://www.moretables.test']);

    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
    ]);

    $html = (string) (new AvailabilityAlertNotification($alert))->toMail($customer)->render();

    expect($html)->toContain('https://www.moretables.test/restaurants/'.$data['restaurant']->slug)
        ->and($html)->not->toContain('https://api.moretables.test/restaurants/');
});

it('lets a customer cancel their availability alert', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
    ]);

    Sanctum::actingAs($customer);

    $this->deleteJson('/api/v1/availability-alerts/'.$alert->id)->assertOk();

    expect($alert->fresh()->status)->toBe(WaitlistStatus::Cancelled);
});

it('does not let a customer cancel another customer availability alert', function () {
    $data = createBookableRestaurant();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($intruder);

    $this->deleteJson('/api/v1/availability-alerts/'.$alert->id)->assertNotFound();
});

it('notifies the customer when a table opens within their alert window', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    $data['restaurant']->update(['timezone' => 'Africa/Lagos']);
    $customer = User::factory()->create();
    $window = availabilityAlertWindow();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'preferred_starts_at' => $window['start_utc'],
        'preferred_ends_at' => $window['end_utc'],
    ]);

    $notified = app(AvailabilityAlertService::class)->processForRestaurant($data['restaurant']->fresh());

    expect($notified)->toBe(1)
        ->and($alert->fresh()->status)->toBe(WaitlistStatus::Notified)
        ->and($alert->fresh()->notified_at)->not->toBeNull();

    Notification::assertSentTo(
        $customer,
        AvailabilityAlertNotification::class,
        function (AvailabilityAlertNotification $notification, array $channels): bool {
            return in_array('mail', $channels, true)
                && in_array('database', $channels, true)
                && in_array(ExpoPushChannel::class, $channels, true);
        },
    );
});

it('does not notify when no table is available in the alert window', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    $data['restaurant']->update(['timezone' => 'Africa/Lagos']);
    $customer = User::factory()->create();

    $start = Carbon::tomorrow('Africa/Lagos')->setTime(5, 0);

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'preferred_starts_at' => $start->copy()->utc(),
        'preferred_ends_at' => $start->copy()->setTime(6, 0)->utc(),
    ]);

    $notified = app(AvailabilityAlertService::class)->processForRestaurant($data['restaurant']->fresh());

    expect($notified)->toBe(0)
        ->and($alert->fresh()->status)->toBe(WaitlistStatus::Waiting);

    Notification::assertNothingSent();
});

it('respects the renotify cooldown', function () {
    Notification::fake();
    config()->set('reservations.availability_alerts.renotify_cooldown_minutes', 30);

    $data = createBookableRestaurant();
    $data['restaurant']->update(['timezone' => 'Africa/Lagos']);
    $customer = User::factory()->create();
    $window = availabilityAlertWindow();

    WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
        'notified_at' => now()->subMinutes(5),
        'preferred_starts_at' => $window['start_utc'],
        'preferred_ends_at' => $window['end_utc'],
    ]);

    $notified = app(AvailabilityAlertService::class)->processForRestaurant($data['restaurant']->fresh());

    expect($notified)->toBe(0);
    Notification::assertNothingSent();
});

it('dispatches an availability alert check when a reservation frees a table', function () {
    Queue::fake();

    $data = createBookableRestaurant();
    $actor = User::factory()->create();
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => User::factory()->create()->id,
    ]);

    app(ReservationService::class)->cancelReservation($reservation, $actor);

    Queue::assertPushed(ProcessRestaurantAvailabilityAlerts::class, fn ($job) => $job->restaurantId === $data['restaurant']->id);
});

it('expires availability alerts whose window has elapsed', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Waiting,
        'preferred_starts_at' => now()->subHours(3),
        'preferred_ends_at' => now()->subHour(),
    ]);

    $this->artisan('app:process-availability-alerts')->assertSuccessful();

    expect($alert->fresh()->status)->toBe(WaitlistStatus::Expired);
});
