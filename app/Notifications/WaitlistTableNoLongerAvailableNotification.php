<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistTableNoLongerAvailableNotification extends Notification implements ShouldQueue
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
            ->subject('Waitlist table no longer available')
            ->greeting('Hello!')
            ->line("The table held for your waitlist offer at {$restaurantName} is no longer available.")
            ->line('If you would still like a table, you can rejoin the waitlist or book another time.')
            ->action('View restaurant', $this->restaurantUrl($this->entry->restaurant->slug));
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->entry->restaurant->name;

        return ExpoPushMessage::make(
            title: 'Table no longer available',
            body: "Your waitlist offer at {$restaurantName} could not be completed.",
        )->data([
            'type' => 'waitlist_table_unavailable',
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
