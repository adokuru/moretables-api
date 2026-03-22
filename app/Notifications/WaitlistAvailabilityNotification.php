<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistAvailabilityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected WaitlistEntry $entry) {}

    public function via(object $notifiable): array
    {
        return ['mail', ExpoPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $restaurantName = $this->entry->restaurant->name;

        return (new MailMessage)
            ->subject('A table is available for your waitlist request')
            ->greeting('Good news!')
            ->line("A table may be available at {$restaurantName}.")
            ->line('Preferred time: '.$this->entry->preferred_starts_at?->toDayDateTimeString())
            ->line('Please confirm with the restaurant as soon as possible.');
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->entry->restaurant->name;

        return ExpoPushMessage::make(
            title: 'Table available',
            body: "A table may be available at {$restaurantName}.",
        )->data([
            'type' => 'waitlist_availability',
            'waitlist_entry_id' => $this->entry->id,
            'restaurant_id' => $this->entry->restaurant_id,
            'status' => $this->entry->status?->value,
            'preferred_starts_at' => $this->entry->preferred_starts_at?->toIso8601String(),
            'expires_at' => $this->entry->expires_at?->toIso8601String(),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'waitlist_entry_id' => $this->entry->id,
        ];
    }
}
