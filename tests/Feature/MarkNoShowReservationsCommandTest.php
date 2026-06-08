<?php

use App\Events\ReservationUpdated;
use App\Models\Reservation;
use App\Models\User;
use App\ReservationStatus;
use App\TableStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('marks overdue eligible reservations as no-show', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 14:00:00', 'UTC'));

    $data = createBookableRestaurant();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => Carbon::parse('2026-04-24 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-24 14:00:00', 'UTC'),
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->expectsOutput('Marked 1 reservation(s) as no-show.')
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::NoShow);
});

it('does not mark reservations still within the no-show grace period', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 13:30:00', 'UTC'));

    $data = createBookableRestaurant();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Confirmed,
        'starts_at' => Carbon::parse('2026-04-24 12:45:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-24 14:45:00', 'UTC'),
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->expectsOutput('Marked 0 reservation(s) as no-show.')
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

it('does not mark reservations with terminal or in-service statuses', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 16:00:00', 'UTC'));

    $data = createBookableRestaurant();

    $startsAt = Carbon::parse('2026-04-24 12:00:00', 'UTC');
    $endsAt = Carbon::parse('2026-04-24 14:00:00', 'UTC');

    $seated = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $completed = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Completed,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $cancelled = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Cancelled,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->expectsOutput('Marked 0 reservation(s) as no-show.')
        ->assertSuccessful();

    expect($seated->fresh()->status)->toBe(ReservationStatus::Seated)
        ->and($completed->fresh()->status)->toBe(ReservationStatus::Completed)
        ->and($cancelled->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('does not mark arrived or partially arrived reservations as no-show', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 16:00:00', 'UTC'));

    $data = createBookableRestaurant();

    $startsAt = Carbon::parse('2026-04-24 12:00:00', 'UTC');
    $endsAt = Carbon::parse('2026-04-24 14:00:00', 'UTC');

    $arrived = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Arrived,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $partiallyArrived = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::PartiallyArrived,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->expectsOutput('Marked 0 reservation(s) as no-show.')
        ->assertSuccessful();

    expect($arrived->fresh()->status)->toBe(ReservationStatus::Arrived)
        ->and($partiallyArrived->fresh()->status)->toBe(ReservationStatus::PartiallyArrived);
});

it('creates a dedicated system user when no users exist', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 14:00:00', 'UTC'));

    expect(User::query()->count())->toBe(0);

    $data = createBookableRestaurant();

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => Carbon::parse('2026-04-24 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-24 14:00:00', 'UTC'),
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->assertSuccessful();

    expect(User::query()->where('email', config('reservations.no_show_system_user_email'))->exists())->toBeTrue();
});

it('dispatches no_show_automated when marking reservations automatically', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 14:00:00', 'UTC'));

    Event::fake([ReservationUpdated::class]);

    $data = createBookableRestaurant();

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => Carbon::parse('2026-04-24 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-24 14:00:00', 'UTC'),
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->assertSuccessful();

    Event::assertDispatched(ReservationUpdated::class, fn (ReservationUpdated $event): bool => $event->action === 'no_show_automated');
});

it('frees an assigned table when marking a reservation as no-show', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-24 14:00:00', 'UTC'));

    $data = createBookableRestaurant();
    $data['table']->update(['status' => TableStatus::Reserved]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::RunningLate,
        'starts_at' => Carbon::parse('2026-04-24 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-24 14:00:00', 'UTC'),
    ]);

    $this->artisan('app:mark-no-show-reservations')
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::NoShow)
        ->and($data['table']->fresh()->status)->toBe(TableStatus::Available);
});
