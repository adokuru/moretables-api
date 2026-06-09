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

class WaitlistOfferExpiredNotification extends Notification implements ShouldQueue, Unsubscribable
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

    public function __construct(protected WaitlistEntry $entry) {}

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
        $restaurantName = $this->entry->restaurant->name;

        return (new MailMessage)
            ->subject('Waitlist offer expired')
            ->greeting('Hello!')
            ->line("The time to respond to your table offer at {$restaurantName} has passed.")
            ->line('If you are still interested, you can rejoin the waitlist or book another time.')
            ->action('View restaurant', $this->restaurantUrl($this->entry->restaurant->slug));
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->entry->restaurant->name;

        return ExpoPushMessage::make(
            title: 'Waitlist offer expired',
            body: "Your table offer at {$restaurantName} has expired.",
        )->data([
            'type' => 'waitlist_offer_expired',
            'waitlist_entry_id' => $this->entry->id,
            'restaurant_id' => $this->entry->restaurant_id,
        ]);
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
