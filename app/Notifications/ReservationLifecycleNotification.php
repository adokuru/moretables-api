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
            title: $this->pushTitle(),
            body: $this->pushBody($restaurantName),
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

    protected function pushTitle(): string
    {
        return match ($this->action) {
            'created' => 'New reservation',
            'updated' => 'Reservation changed',
            'cancelled' => 'Reservation canceled',
            'guest_added' => 'Added to reservation',
            'upcoming_reminder' => 'Reservation reminder',
            default => 'Reservation update',
        };
    }

    protected function pushBody(string $restaurantName): string
    {
        return match ($this->action) {
            'created' => "Your reservation at {$restaurantName} is confirmed.",
            'updated' => "Your reservation at {$restaurantName} has been updated.",
            'cancelled' => "Your reservation at {$restaurantName} was canceled.",
            'guest_added' => "You've been added to a reservation at {$restaurantName}.",
            'upcoming_reminder' => "Your reservation at {$restaurantName} is coming up soon.",
            default => "There is an update to your reservation at {$restaurantName}.",
        };
    }
}
