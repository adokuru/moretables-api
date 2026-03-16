<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Reservation $reservation,
        protected string $action,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $restaurantName = $this->reservation->restaurant->name;

        return (new MailMessage)
            ->subject("Your reservation was {$this->action}")
            ->greeting('Hello!')
            ->line("Your reservation at {$restaurantName} was {$this->action}.")
            ->line('Reference: '.$this->reservation->reservation_reference)
            ->line('Time: '.$this->reservation->starts_at?->toDayDateTimeString())
            ->line('Party size: '.$this->reservation->party_size);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'action' => $this->action,
        ];
    }
}
