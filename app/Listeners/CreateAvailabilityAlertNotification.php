<?php

namespace App\Listeners;

use App\Events\WaitlistEntryUpdated;
use App\Listeners\Concerns\NotifiesRestaurantStaff;
use App\Models\WaitlistEntry;
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
        $window = $this->formatWindow($alert);

        [$title, $message] = match ($event->action) {
            'created' => ['New availability alert', sprintf('%s asked to be notified for a table of %d, %s.', $guestName, $alert->party_size, $window)],
            'notified' => ['Table opened up', sprintf('%s was notified that a table is now available for %s.', $guestName, $window)],
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

    /**
     * "Mon, Aug 10, 6:00 PM - 9:00 PM" in the restaurant's own timezone.
     */
    private function formatWindow(WaitlistEntry $alert): string
    {
        if ($alert->preferred_starts_at === null) {
            return 'an unscheduled window';
        }

        $timezone = $alert->restaurant?->timezone ?: config('app.timezone');
        $start = $alert->preferred_starts_at->copy()->timezone($timezone);
        $end = $alert->preferred_ends_at?->copy()->timezone($timezone);

        return $end
            ? sprintf('%s, %s - %s', $start->format('D, M j'), $start->format('g:i A'), $end->format('g:i A'))
            : $start->format('D, M j \a\t g:i A');
    }
}
