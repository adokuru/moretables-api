<?php

namespace App\Notifications;

use App\Models\Restaurant;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Bell-only restaurant activity (new reservation, table assigned, cancelled,
 * no-show) — database channel only, no mail/push. Deliberately separate from
 * OwnerReservationLifecycleNotification (mail/push, owner-only): this one
 * goes to all restaurant staff, purely to feed the dashboard's notification
 * dropdown.
 */
class RestaurantActivityNotification extends Notification implements ShouldQueue
{
    use Queueable, UsesNotificationQueues;

    public function __construct(
        protected Restaurant $restaurant,
        protected string $type,
        protected string $title,
        protected string $message,
        protected ?int $reservationId = null,
        /** Frontend path to open when this notification is clicked — null means "just mark it read." */
        protected ?string $route = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'restaurant_id' => $this->restaurant->id,
            'restaurant_name' => $this->restaurant->name,
            'reservation_id' => $this->reservationId,
            'route' => $this->route,
        ];
    }
}
