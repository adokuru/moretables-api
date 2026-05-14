<?php

namespace App\Notifications;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestReservationLifecycleMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Reservation $reservation,
        protected GuestContact|ReservationGuest|User $guestRecipient,
        protected string $action,
        protected ?int $daysUntilReservation = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservation->loadMissing('restaurant');

        $restaurant = $this->reservation->restaurant;
        $subject = $this->subject($restaurant->name);

        return (new MailMessage)->subject($subject)->view(
            [
                'html' => 'emails.reservation-lifecycle',
                'text' => 'emails.reservation-lifecycle-text',
            ],
            [
                'subject' => $subject,
                'subtitle' => $this->subtitle(),
                'guestName' => $this->guestName(),
                'restaurantName' => $restaurant->name,
                'formattedDate' => $this->formattedDate(),
                'formattedTime' => $this->formattedTime(),
                'partySize' => $this->reservation->party_size,
                'extraBody' => $this->extraBody(),
                'ctaUrl' => config('app.url').'/reservations/'.$this->reservation->reservation_reference,
                'ctaLabel' => 'Manage reservation',
                'signOff' => $this->signOff(),
                'footerLink1Url' => config('app.url'),
                'footerLink1Label' => 'Earn rewards',
                'footerLink2Url' => config('app.url').'/unsubscribe',
                'footerLink2Label' => 'Unsubscribe',
            ],
        );
    }

    protected function subject(string $restaurantName): string
    {
        return match ($this->action) {
            'created' => "Reservation confirmed at {$restaurantName}",
            'updated' => "Reservation changed - {$restaurantName}",
            'cancelled' => "Reservation canceled - {$restaurantName}",
            'guest_added' => "You have been added to a reservation at {$restaurantName}",
            'upcoming_reminder' => "Your reservation is coming up at {$restaurantName} in {$this->daysUntilReservationLabel()}",
            default => "Reservation update - {$restaurantName}",
        };
    }

    protected function subtitle(): string
    {
        return match ($this->action) {
            'created' => 'Your reservation has been successfully confirmed! 🎉',
            'updated' => 'Your reservation has been updated.',
            'cancelled' => 'Your reservation has been cancelled.',
            'guest_added' => "You've been added to an upcoming reservation.",
            'upcoming_reminder' => 'Just a quick reminder about your upcoming reservation:',
            default => 'There is an update to your reservation.',
        };
    }

    protected function signOff(): string
    {
        return match ($this->action) {
            'created' => 'Enjoy your meal,',
            'upcoming_reminder' => 'See you soon!',
            default => 'Thanks,',
        };
    }

    protected function extraBody(): ?string
    {
        return match ($this->action) {
            'created' => "You're all set for a great experience.\n\nYou can manage or update your reservation anytime",
            default => null,
        };
    }

    protected function guestName(): string
    {
        $guestName = match (true) {
            $this->guestRecipient instanceof GuestContact => trim($this->guestRecipient->first_name.' '.($this->guestRecipient->last_name ?? '')),
            $this->guestRecipient instanceof ReservationGuest => trim($this->guestRecipient->attendee_name),
            $this->guestRecipient instanceof User => trim($this->guestRecipient->fullName()),
            default => '',
        };

        return $guestName !== '' ? $guestName : 'Guest';
    }

    protected function daysUntilReservationLabel(): string
    {
        $daysUntilReservation = max(1, (int) ($this->daysUntilReservation ?? 1));

        return $daysUntilReservation === 1
            ? '1 day'
            : "{$daysUntilReservation} days";
    }

    protected function formattedDate(): string
    {
        if ($this->reservation->starts_at === null) {
            return '—';
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');

        return $this->reservation->starts_at
            ->copy()
            ->timezone($restaurantTimezone)
            ->format('jS F, Y');
    }

    protected function formattedTime(): string
    {
        if ($this->reservation->starts_at === null) {
            return '—';
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');

        return $this->reservation->starts_at
            ->copy()
            ->timezone($restaurantTimezone)
            ->format('g:iA');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'guest_contact_id' => $this->guestRecipient instanceof GuestContact ? $this->guestRecipient->id : null,
            'reservation_guest_id' => $this->guestRecipient instanceof ReservationGuest ? $this->guestRecipient->id : null,
            'user_id' => $this->guestRecipient instanceof User ? $this->guestRecipient->id : null,
            'action' => $this->action,
            'upcoming_days' => $this->daysUntilReservation,
        ];
    }
}
