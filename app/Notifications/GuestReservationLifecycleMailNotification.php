<?php

namespace App\Notifications;

use App\Models\GuestContact;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestReservationLifecycleMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Reservation $reservation,
        protected GuestContact $guestContact,
        protected string $action,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $restaurantName = $this->reservation->restaurant->name;
        $guestName = trim($this->guestContact->first_name.' '.($this->guestContact->last_name ?? ''));

        $subject = match ($this->action) {
            'created' => "Reservation confirmed at {$restaurantName}",
            'updated' => "Reservation updated — {$restaurantName}",
            'cancelled' => "Reservation cancelled — {$restaurantName}",
            default => "Reservation update — {$restaurantName}",
        };

        $intro = match ($this->action) {
            'created' => "A reservation has been booked for you at {$restaurantName}.",
            'updated' => "Your reservation at {$restaurantName} has been updated.",
            'cancelled' => "Your reservation at {$restaurantName} has been cancelled.",
            default => "There is an update to your reservation at {$restaurantName}.",
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting($guestName !== '' ? "Hello {$guestName}," : 'Hello,')
            ->line($intro)
            ->line('Reference: '.$this->reservation->reservation_reference)
            ->line('Time: '.$this->reservation->starts_at?->toDayDateTimeString())
            ->line('Party size: '.$this->reservation->party_size)
            ->line('If you have questions, contact the restaurant directly.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'guest_contact_id' => $this->guestContact->id,
            'action' => $this->action,
        ];
    }
}
