<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestWaitlistCreatedMailNotification extends Notification implements ShouldQueue, Unsubscribable
{
    use Queueable, UsesNotificationQueues;

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
            ->subject("You're on the waitlist at {$restaurantName}")
            ->greeting($name !== '' ? "Hello {$name}," : 'Hello,')
            ->line("You've been added to the waitlist at {$restaurantName}.")
            ->line('Preferred time: '.$this->entry->preferred_starts_at?->toDayDateTimeString())
            ->line('Party size: '.$this->entry->party_size)
            ->line("We'll let you know as soon as a table becomes available.");
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
