<?php

namespace App\Listeners;

use App\Events\WaitlistEntryUpdated;
use App\Listeners\Concerns\NotifiesRestaurantStaff;
use App\Notifications\RestaurantActivityNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Feeds the dashboard's notification bell for "Notify Me" (availability
 * alert) activity — mirrors CreateRestaurantActivityNotification's shape,
 * but off WaitlistEntryUpdated instead of ReservationUpdated. Deliberately
 * scoped to availability alerts only (`type = AvailabilityAlert`), not
 * regular walk-in waitlist entries — those already have full visibility on
 * the dashboard's own Waitlist column, unlike "Notify Me" which lives on its
 * own less-visited page (/dashboard/notice-me).
 */
class CreateAvailabilityAlertNotification implements ShouldQueue
{
    use NotifiesRestaurantStaff;

    private const NOTIFIABLE_ACTIONS = ['created', 'notified'];

    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(WaitlistEntryUpdated $event): void
    {
        $alert = $event->entry;

        if (! $alert->isAvailabilityAlert() || ! in_array($event->action, self::NOTIFIABLE_ACTIONS, true)) {
            return;
        }

        $alert->loadMissing(['restaurant', 'user', 'guestContact']);
        $restaurant = $alert->restaurant;

        $guestName = $alert->user?->fullName()
            ?? trim(($alert->guestContact?->first_name ?? '').' '.($alert->guestContact?->last_name ?? ''));
        $guestName = $guestName !== '' ? $guestName : 'A guest';

        [$title, $message] = match ($event->action) {
            'created' => ['New availability alert', sprintf('%s asked to be notified for a table of %d.', $guestName, $alert->party_size)],
            'notified' => ['Table opened up', sprintf('%s was notified that a table is now available.', $guestName)],
        };

        Notification::send(
            $this->restaurantStaff($restaurant),
            new RestaurantActivityNotification(
                restaurant: $restaurant,
                type: 'availability_alert.'.$event->action,
                title: $title,
                message: $message,
                route: '/dashboard/notice-me',
            ),
        );
    }
}
