<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestWaitlistTableAvailableMailNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

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
            ->action('Book your table', $this->restaurantUrl($this->entry->restaurant->slug))
            ->line('This offer is time-limited, so please confirm as soon as you can.');
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
