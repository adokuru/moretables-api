<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestWaitlistOfferExpiredMailNotification extends Notification implements ShouldQueue, Unsubscribable
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
            ->subject("Waitlist offer expired — {$restaurantName}")
            ->greeting($name !== '' ? "Hello {$name}," : 'Hello,')
            ->line("The time to respond to your table offer at {$restaurantName} has passed.")
            ->line('If you are still interested, you can rejoin the waitlist or book another time.')
            ->action('View restaurant', $this->restaurantUrl($this->entry->restaurant->slug));
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
