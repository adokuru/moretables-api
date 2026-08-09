<?php

namespace App\Listeners;

use App\Events\ReservationUpdated;
use App\Listeners\Concerns\NotifiesRestaurantStaff;
use App\Models\Reservation;
use App\Notifications\RestaurantActivityNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Feeds the dashboard's notification bell off the same ReservationUpdated
 * broadcast every mutation already dispatches — no new dispatch call sites
 * needed in ReservationService. Only a curated subset of actions produce a
 * persistent notification; everything else (service-stage micro-transitions,
 * etc.) still broadcasts live for the dashboard but doesn't clutter the bell.
 */
class CreateRestaurantActivityNotification implements ShouldQueue
{
    use NotifiesRestaurantStaff;

    private const NOTIFIABLE_ACTIONS = ['created', 'table_assigned', 'cancelled', 'no_show'];

    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(ReservationUpdated $event): void
    {
        if (! in_array($event->action, self::NOTIFIABLE_ACTIONS, true)) {
            return;
        }

        $reservation = $event->reservation;
        $reservation->loadMissing(['restaurant', 'user', 'guestContact', 'table']);
        $restaurant = $reservation->restaurant;

        $guestName = $reservation->user?->fullName()
            ?? trim(($reservation->guestContact?->first_name ?? '').' '.($reservation->guestContact?->last_name ?? ''));
        $guestName = $guestName !== '' ? $guestName : 'A guest';
        $when = $this->formatWhen($reservation);

        [$title, $message] = match ($event->action) {
            'created' => ['New reservation', sprintf('%s booked a table for %d for %s.', $guestName, $reservation->party_size, $when)],
            // table_assigned is always a staff action (customers never assign
            // tables), so naming who did it and which table is meaningful here
            // in a way it wouldn't be for e.g. a self-service cancellation.
            'table_assigned' => ['Table assigned', sprintf(
                '%s (%s) was assigned to Table %s%s.',
                $guestName,
                $when,
                $reservation->table?->name ?? '—',
                $event->actor ? ' by '.$event->actor->fullName() : '',
            )],
            'cancelled' => ['Reservation cancelled', sprintf("%s's reservation for %s was cancelled.", $guestName, $when)],
            'no_show' => ['No-show', sprintf('%s (%s) was marked as a no-show.', $guestName, $when)],
        };

        Notification::send(
            $this->restaurantStaff($restaurant),
            new RestaurantActivityNotification(
                restaurant: $restaurant,
                type: 'reservation.'.$event->action,
                title: $title,
                message: $message,
                reservationId: $reservation->id,
            ),
        );
    }

    /**
     * "Mon, Aug 10 at 6:00 PM" in the restaurant's own timezone — bell
     * messages otherwise had no indication of *which* reservation this was
     * (this restaurant can have several a day, days out from when the
     * notification arrives), just a guest name and party size.
     */
    private function formatWhen(Reservation $reservation): string
    {
        if ($reservation->starts_at === null) {
            return 'an unscheduled time';
        }

        $timezone = $reservation->restaurant?->timezone ?: config('app.timezone');

        return $reservation->starts_at->copy()->timezone($timezone)->format('D, M j \a\t g:i A');
    }
}
