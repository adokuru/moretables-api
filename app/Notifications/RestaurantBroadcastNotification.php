<?php

namespace App\Notifications;

use App\Models\Restaurant;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RestaurantBroadcastNotification extends Notification implements ShouldQueue
{
    use Queueable, UsesNotificationQueues;

    public function __construct(
        protected Restaurant $restaurant,
        protected string $title,
        protected string $message,
    ) {}

    /**
     * Get the notification channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->notify_push_notifications) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        return ExpoPushMessage::make(
            title: $this->title,
            body: $this->message,
        )->data([
            'type' => 'restaurant_broadcast',
            'restaurant_id' => $this->restaurant->id,
            'restaurant_name' => $this->restaurant->name,
            'restaurant_slug' => $this->restaurant->slug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'restaurant_broadcast',
            'restaurant_id' => $this->restaurant->id,
            'restaurant_name' => $this->restaurant->name,
            'title' => $this->title,
            'message' => $this->message,
        ];
    }
}
