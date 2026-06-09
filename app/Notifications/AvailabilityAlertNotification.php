<?php

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Concerns\UsesNotificationQueues;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AvailabilityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, UsesNotificationQueues;

    public function __construct(protected WaitlistEntry $alert) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($notifiable->notify_push_notifications) {
            $channels[] = ExpoPushChannel::class;
        }

        if ($this->alert->whatsapp_updates && $notifiable->notify_sms_alerts) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $restaurantName = $this->alert->restaurant->name;

        return (new MailMessage)
            ->subject("A table is available at {$restaurantName}")
            ->greeting('Good news!')
            ->line("A table for {$this->alert->party_size} is now available at {$restaurantName}.")
            ->line('Your requested time: '.$this->windowLabel())
            ->action('Book now', url('/restaurants/'.$this->alert->restaurant->slug))
            ->line('Tables go fast — book as soon as you can.');
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->alert->restaurant->name;

        return ExpoPushMessage::make(
            title: 'A table just opened up',
            body: "A table for {$this->alert->party_size} is available at {$restaurantName} ({$this->windowLabel()}).",
        )->data($this->payload());
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::template(
            (string) config('services.whatsapp.availability_alert_template'),
            [
                $this->alert->restaurant->name,
                (string) $this->alert->party_size,
                $this->windowLabel(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'type' => 'availability_alert',
            'waitlist_entry_id' => $this->alert->id,
            'restaurant_id' => $this->alert->restaurant_id,
            'restaurant_slug' => $this->alert->restaurant->slug,
            'party_size' => $this->alert->party_size,
            'window_start' => $this->alert->preferred_starts_at?->toIso8601String(),
            'window_end' => $this->alert->preferred_ends_at?->toIso8601String(),
        ];
    }

    protected function windowLabel(): string
    {
        $timezone = $this->alert->restaurant->timezone ?: config('app.timezone');
        $start = $this->alert->preferred_starts_at?->copy()->setTimezone($timezone);
        $end = $this->alert->preferred_ends_at?->copy()->setTimezone($timezone);

        if (! $start instanceof CarbonInterface) {
            return 'your selected time';
        }

        $label = $start->format('D, M j, g:i A');

        if ($end instanceof CarbonInterface) {
            $label .= ' – '.$end->format('g:i A');
        }

        return $label;
    }
}
