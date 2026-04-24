<?php

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\GuestReservationLifecycleMailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends upcoming reservation reminder emails for due reservations and marks the cadence as sent', function (): void {
    Notification::fake();

    Carbon::setTestNow(Carbon::parse('2026-04-24 12:00:00', 'UTC'));

    config([
        'reservations.upcoming_reminder_days_before' => [3],
        'reservations.upcoming_reminder_window_minutes' => 60,
    ]);

    $data = createBookableRestaurant();
    $owner = User::factory()->create([
        'first_name' => 'Owner',
        'last_name' => 'Diner',
        'email' => 'owner@example.com',
    ]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $owner->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 3,
        'starts_at' => Carbon::parse('2026-04-27 12:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-27 14:30:00', 'UTC'),
        'metadata' => null,
    ]);

    ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $data['restaurant']->id,
        'attendee_name' => 'Added Diner',
        'email_address' => 'added.diner@example.com',
        'email_normalized' => 'added.diner@example.com',
        'phone_number' => '+2348000000011',
    ]);

    $this->artisan('app:send-upcoming-reservation-reminders')
        ->expectsOutput('Sent 2 upcoming reservation reminder email(s).')
        ->assertSuccessful();

    Notification::assertSentOnDemand(GuestReservationLifecycleMailNotification::class, function ($notification, $channels, $notifiable): bool {
        return ($notifiable->routes['mail'] ?? null) === 'owner@example.com'
            && $notification->toArray((object) [])['action'] === 'upcoming_reminder'
            && $notification->toArray((object) [])['upcoming_days'] === 3;
    });

    Notification::assertSentOnDemand(GuestReservationLifecycleMailNotification::class, function ($notification, $channels, $notifiable): bool {
        return ($notifiable->routes['mail'] ?? null) === 'added.diner@example.com'
            && $notification->toArray((object) [])['action'] === 'upcoming_reminder'
            && $notification->toArray((object) [])['upcoming_days'] === 3;
    });

    $reservation->refresh();

    expect($reservation->hasUpcomingReminderSent(3))->toBeTrue();
});

it('does not resend an upcoming reminder cadence that has already been marked as sent', function (): void {
    Notification::fake();

    Carbon::setTestNow(Carbon::parse('2026-04-24 12:00:00', 'UTC'));

    config([
        'reservations.upcoming_reminder_days_before' => [3],
        'reservations.upcoming_reminder_window_minutes' => 60,
    ]);

    $data = createBookableRestaurant();
    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'first_name' => 'Walk',
        'last_name' => 'Guest',
        'email' => 'walk.guest@example.com',
    ]);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'guest_contact_id' => $guestContact->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => Carbon::parse('2026-04-27 12:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-04-27 14:30:00', 'UTC'),
        'metadata' => [
            'upcoming_reminders_sent' => [
                '3' => '2026-04-24T12:00:00+00:00',
            ],
        ],
    ]);

    $this->artisan('app:send-upcoming-reservation-reminders')
        ->expectsOutput('Sent 0 upcoming reservation reminder email(s).')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
