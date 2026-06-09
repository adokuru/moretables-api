<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationLifecycleNotification extends Notification implements ShouldQueue, Unsubscribable
{
    use Queueable, UsesNotificationQueues;

    public function __construct(
        protected Reservation $reservation,
        protected string $action,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($notifiable->notify_push_notifications) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new GuestReservationLifecycleMailNotification(
            $this->reservation,
            $notifiable,
            $this->action,
        ))->toMail($notifiable);
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->reservation->restaurant->name;

        return ExpoPushMessage::make(
            title: 'Reservation update',
            body: "Your reservation at {$restaurantName} was {$this->action}.",
        )->data([
            'type' => 'reservation_lifecycle',
            'reservation_id' => $this->reservation->id,
            'restaurant_id' => $this->reservation->restaurant_id,
            'status' => $this->reservation->status?->value,
            'action' => $this->action,
            'reference' => $this->reservation->reservation_reference,
            'starts_at' => $this->reservation->starts_at?->toIso8601String(),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'action' => $this->action,
        ];
    }
}
