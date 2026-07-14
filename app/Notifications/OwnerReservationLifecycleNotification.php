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
        $channels = ['mail', 'database'];

        if ($notifiable->notify_push_notifications) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        return ExpoPushMessage::make(
            title: $this->pushTitle(),
            body: $this->pushBody(),
        )->data($this->notificationData());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->notificationData();
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

    protected function pushTitle(): string
    {
        return match ($this->action) {
            'created' => 'New reservation',
            'updated' => 'Reservation modified',
            'cancelled' => 'Reservation cancelled',
            default => 'Reservation update',
        };
    }

    protected function pushBody(): string
    {
        $action = match ($this->action) {
            'created' => 'booked',
            'updated' => 'modified',
            'cancelled' => 'cancelled',
            default => 'updated',
        };

        return sprintf(
            '%s %s a reservation for %d %s at %s.',
            $this->guestName(),
            $action,
            $this->reservation->party_size,
            $this->reservation->party_size === 1 ? 'guest' : 'guests',
            $this->reservation->starts_at?->copy()->timezone($this->reservation->restaurant->timezone ?: config('app.timezone'))->format('g:i A') ?? 'an upcoming service',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationData(): array
    {
        $this->reservation->loadMissing(['restaurant', 'table', 'user', 'guestContact']);

        return [
            'type' => 'reservation_lifecycle',
            'title' => $this->pushTitle(),
            'message' => $this->pushBody(),
            'action' => $this->action,
            'reservation_id' => $this->reservation->id,
            'restaurant_id' => $this->reservation->restaurant_id,
            'restaurant_name' => $this->reservation->restaurant->name,
            'restaurant_slug' => $this->reservation->restaurant->slug,
            'guest_name' => $this->guestName(),
            'party_size' => $this->reservation->party_size,
            'reference' => $this->reservation->reservation_reference,
            'status' => $this->reservation->status?->value,
            'starts_at' => $this->reservation->starts_at?->toIso8601String(),
        ];
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
