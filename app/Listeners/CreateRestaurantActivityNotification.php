<?php

namespace App\Listeners;

use App\Events\ReservationUpdated;
use App\Listeners\Concerns\NotifiesRestaurantStaff;
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

        [$title, $message] = match ($event->action) {
            'created' => ['New reservation', sprintf('%s booked a table for %d.', $guestName, $reservation->party_size)],
            // table_assigned is always a staff action (customers never assign
            // tables), so naming who did it and which table is meaningful here
            // in a way it wouldn't be for e.g. a self-service cancellation.
            'table_assigned' => ['Table assigned', sprintf(
                '%s was assigned to Table %s%s.',
                $guestName,
                $reservation->table?->name ?? '—',
                $event->actor ? ' by '.$event->actor->fullName() : '',
            )],
            'cancelled' => ['Reservation cancelled', sprintf("%s's reservation was cancelled.", $guestName)],
            'no_show' => ['No-show', sprintf('%s was marked as a no-show.', $guestName)],
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
}
