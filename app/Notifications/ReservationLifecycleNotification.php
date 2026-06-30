<?php

namespace App\Notifications;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationLifecycleNotification extends Notification implements ShouldQueue, Unsubscribable
{
    use Queueable, UsesNotificationQueues;

    public function __construct(
        protected Reservation $reservation,
        protected string $action,
        protected ?int $hoursUntilReservation = null,
    ) {}

    /**
     * Get the notification channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        $hasEmail = false;

        if ($notifiable instanceof User && $notifiable->email) {
            $hasEmail = true;
        } elseif ($notifiable instanceof GuestContact && $notifiable->email) {
            $hasEmail = filter_var($notifiable->email, FILTER_VALIDATE_EMAIL) !== false;
        } elseif ($notifiable instanceof ReservationGuest && $notifiable->email_address) {
            $hasEmail = filter_var($notifiable->email_address, FILTER_VALIDATE_EMAIL) !== false;
        }

        if ($hasEmail) {
            $channels[] = 'mail';
        }

        if ($notifiable instanceof User) {
            $channels[] = 'database';

            if ($notifiable->notify_push_notifications) {
                $channels[] = ExpoPushChannel::class;
            }

            if ($notifiable->notify_sms_alerts && $notifiable->phone) {
                $channels[] = WhatsAppChannel::class;
            }
        } elseif ($notifiable instanceof GuestContact) {
            if ($notifiable->phone) {
                $channels[] = WhatsAppChannel::class;
            }
        } elseif ($notifiable instanceof ReservationGuest) {
            if ($notifiable->phone_number) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daysUntilReservation = $this->hoursUntilReservation !== null
            ? (int) ceil($this->hoursUntilReservation / 24)
            : null;

        return (new GuestReservationLifecycleMailNotification(
            $this->reservation,
            $notifiable,
            $this->action,
            $daysUntilReservation,
        ))->toMail($notifiable);
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $restaurantName = $this->reservation->restaurant->name;

        return ExpoPushMessage::make(
            title: $this->pushTitle(),
            body: $this->pushBody($restaurantName),
        )->data([
            'type' => 'reservation_lifecycle',
            'reservation_id' => $this->reservation->id,
            'restaurant_id' => $this->reservation->restaurant_id,
            'restaurant_slug' => $this->reservation->restaurant->slug,
            'status' => $this->reservation->status?->value,
            'action' => $this->action,
            'reference' => $this->reservation->reservation_reference,
            'starts_at' => $this->reservation->starts_at?->toIso8601String(),
        ]);
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        $template = $this->whatsAppTemplate();

        if ($template === null || $template === '') {
            return null;
        }

        $this->reservation->loadMissing('restaurant');

        $params = [
            $this->guestName($notifiable),
            $this->reservation->restaurant->name,
            $this->formattedDateTime(),
            (string) $this->reservation->party_size,
            (string) $this->reservation->reservation_reference,
        ];

        if ($this->action === 'upcoming_reminder') {
            $params[] = $this->timeLabel();
        }

        $message = WhatsAppMessage::template($template, $params);
        $hasUrlButton = (bool) config('services.whatsapp.reservation_templates_have_url_button');

        if ($hasUrlButton) {
            $suffix = $this->urlButtonSuffix();

            if ($suffix !== null) {
                $message->urlButton($suffix);
            }
        }

        if ($this->shouldOfferCancelButton($notifiable)) {
            $message->quickReplyButton(
                "cancel_reservation:{$this->reservation->id}",
                $hasUrlButton ? 1 : 0,
            );
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'action' => $this->action,
        ];
    }

    /**
     * Resolve the configured Meta template name for the current lifecycle action.
     */
    protected function whatsAppTemplate(): ?string
    {
        if (! in_array($this->action, ['created', 'updated', 'cancelled', 'guest_added', 'upcoming_reminder'], true)) {
            return null;
        }

        return (string) config("services.whatsapp.reservation_{$this->action}_template");
    }

    /**
     * Only the primary booker may cancel from chat, and only on messages where
     * the reservation is still active. The webhook re-checks authorization and
     * the cancellation cutoff; this just controls whether the button is wired.
     */
    protected function shouldOfferCancelButton(object $notifiable): bool
    {
        if (! config('services.whatsapp.reservation_templates_have_cancel_button')) {
            return false;
        }

        if (! in_array($this->action, ['created', 'updated', 'upcoming_reminder'], true)) {
            return false;
        }

        return ($notifiable instanceof User && $notifiable->id === $this->reservation->user_id)
            || ($notifiable instanceof GuestContact && $notifiable->id === $this->reservation->guest_contact_id);
    }

    /**
     * Path appended to the template's dynamic URL button base (the frontend URL).
     * Cancelled reservations link to the restaurant page to rebook; everything
     * else deep-links to the reservation management page.
     */
    protected function urlButtonSuffix(): ?string
    {
        $slug = $this->reservation->restaurant->slug;

        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->action === 'cancelled'
            ? "restaurants/{$slug}"
            : "restaurants/{$slug}/reservations?reservation_id={$this->reservation->id}";
    }

    /**
     * Resolve the guest name based on the notifiable type.
     */
    protected function guestName(object $notifiable): string
    {
        $guestName = match (true) {
            $notifiable instanceof GuestContact => trim($notifiable->first_name.' '.($notifiable->last_name ?? '')),
            $notifiable instanceof ReservationGuest => trim($notifiable->attendee_name),
            $notifiable instanceof User => trim($notifiable->fullName()),
            default => '',
        };

        return $guestName !== '' ? $guestName : 'Guest';
    }

    /**
     * Human-readable time window for upcoming reminders (e.g. '24 hours', '1 hour').
     */
    protected function timeLabel(): string
    {
        if ($this->hoursUntilReservation === null) {
            return 'soon';
        }

        return $this->hoursUntilReservation === 1
            ? '1 hour'
            : "{$this->hoursUntilReservation} hours";
    }

    /**
     * Format the reservation start date and time.
     */
    protected function formattedDateTime(): string
    {
        if ($this->reservation->starts_at === null) {
            return '—';
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');

        return $this->reservation->starts_at
            ->copy()
            ->timezone($restaurantTimezone)
            ->format('l, F j, Y \a\t g:i A');
    }

    protected function pushTitle(): string
    {
        return match ($this->action) {
            'created' => 'New reservation',
            'updated' => 'Reservation changed',
            'cancelled' => 'Reservation canceled',
            'guest_added' => 'Added to reservation',
            'upcoming_reminder' => 'Reservation reminder',
            'review_request' => 'How was your visit?',
            default => 'Reservation update',
        };
    }

    protected function pushBody(string $restaurantName): string
    {
        return match ($this->action) {
            'created' => "Your reservation at {$restaurantName} is confirmed.",
            'updated' => "Your reservation at {$restaurantName} has been updated.",
            'cancelled' => "Your reservation at {$restaurantName} was canceled.",
            'guest_added' => "You've been added to a reservation at {$restaurantName}.",
            'upcoming_reminder' => "Your reservation at {$restaurantName} is coming up soon.",
            'review_request' => "Tell us how your visit to {$restaurantName} went.",
            default => "There is an update to your reservation at {$restaurantName}.",
        };
    }
}
