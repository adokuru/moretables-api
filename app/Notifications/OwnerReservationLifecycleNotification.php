<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OwnerReservationLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable, UsesNotificationQueues;

    public function __construct(
        protected Reservation $reservation,
        protected string $action,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservation->loadMissing(['restaurant', 'table', 'user', 'guestContact']);

        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello,')
            ->line($this->headline())
            ->line('Restaurant: '.$this->reservation->restaurant->name)
            ->line('Guest: '.$this->guestName())
            ->line('Party size: '.$this->reservation->party_size)
            ->line('Date and time: '.$this->formattedDateTime())
            ->line('Reference: '.$this->reservation->reservation_reference)
            ->when($this->reservation->notes, fn (MailMessage $message): MailMessage => $message->line('Special request: '.$this->reservation->notes));
    }

    protected function subject(): string
    {
        return match ($this->action) {
            'created' => 'New reservation - '.$this->reservation->restaurant->name,
            'updated' => 'Reservation updated - '.$this->reservation->restaurant->name,
            'cancelled' => 'Reservation cancelled - '.$this->reservation->restaurant->name,
            default => 'Reservation update - '.$this->reservation->restaurant->name,
        };
    }

    protected function headline(): string
    {
        return match ($this->action) {
            'created' => 'A new reservation has been created.',
            'updated' => 'A reservation has been updated.',
            'cancelled' => 'A reservation has been cancelled.',
            default => 'There is a reservation update.',
        };
    }

    protected function guestName(): string
    {
        $name = $this->reservation->user?->fullName()
            ?? trim(($this->reservation->guestContact?->first_name ?? '').' '.($this->reservation->guestContact?->last_name ?? ''));

        return $name !== '' ? $name : 'Guest';
    }

    protected function formattedDateTime(): string
    {
        if ($this->reservation->starts_at === null) {
            return 'Not set';
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');

        return $this->reservation->starts_at
            ->copy()
            ->timezone($restaurantTimezone)
            ->format('l, F j, Y \a\t g:i A');
    }
}
