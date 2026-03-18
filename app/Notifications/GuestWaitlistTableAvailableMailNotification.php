<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestWaitlistTableAvailableMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected WaitlistEntry $entry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $restaurantName = $this->entry->restaurant->name;
        $guest = $this->entry->guestContact;
        $name = $guest ? trim($guest->first_name.' '.($guest->last_name ?? '')) : '';

        return (new MailMessage)
            ->subject("A table may be available at {$restaurantName}")
            ->greeting($name !== '' ? "Hello {$name}," : 'Hello,')
            ->line("Good news — a table may be available at {$restaurantName} for your waitlist request.")
            ->line('Preferred time: '.$this->entry->preferred_starts_at?->toDayDateTimeString())
            ->line('Please open the MoreTables app or contact the restaurant as soon as possible to confirm.')
            ->line('This offer is time-limited.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'waitlist_entry_id' => $this->entry->id,
        ];
    }
}
