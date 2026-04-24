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
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        $this->reservation->loadMissing('restaurant.media');

        $restaurant = $this->reservation->restaurant;
        $subject = $this->subject($restaurant->name);

        return (new MailMessage)->subject($subject)->view(
            [
                'html' => 'emails.reservation-lifecycle',
                'text' => 'emails.reservation-lifecycle-text',
            ],
            [
                'subject' => $subject,
                'title' => $this->title(),
                'subtitle' => $this->subtitle(),
                'logoUrl' => asset('logo.png'),
                'restaurantName' => $restaurant->name,
                'restaurantImageUrl' => $this->restaurantImageUrl(),
                'restaurantInitial' => strtoupper(substr($restaurant->name, 0, 1)),
                'tableInfo' => $this->tableInfo(),
                'guestName' => $this->guestName(),
                'confirmationNumber' => $this->reservation->reservation_reference,
                'menuUrl' => $restaurant->menu_link ?: null,
                'directionsUrl' => $this->directionsUrl(),
                'showRestaurantContactDetails' => $this->showRestaurantContactDetails(),
                'addressLineOne' => $this->addressLineOne(),
                'addressLineTwo' => $this->addressLineTwo(),
                'restaurantPhone' => $restaurant->phone ?: null,
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

    protected function title(): string
    {
        $restaurantName = $this->reservation->restaurant->name;

        return match ($this->action) {
            'created' => 'Reservation confirmed',
            'updated' => 'Reservation changed',
            'cancelled' => 'Reservation canceled',
            'guest_added' => 'You have been added to the below reservation',
            'upcoming_reminder' => "Your reservation is coming up at {$restaurantName} in {$this->daysUntilReservationLabel()}",
            default => 'Reservation update',
        };
    }

    protected function subtitle(): string
    {
        return match ($this->action) {
            'created' => 'Thanks for using MoreTables',
            'updated' => 'Here are the new details:',
            'cancelled' => "You've successfully canceled your reservation at",
            'guest_added' => 'Here are the details',
            'upcoming_reminder' => 'Here are the details',
            default => 'There is an update to your reservation.',
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

    protected function tableInfo(): string
    {
        $formattedStartsAt = $this->formattedStartsAt();

        if ($formattedStartsAt === null) {
            return 'Table for '.$this->reservation->party_size;
        }

        return 'Table for '.$this->reservation->party_size.' on '.$formattedStartsAt;
    }

    protected function formattedStartsAt(): ?string
    {
        if ($this->reservation->starts_at === null) {
            return null;
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');

        return $this->reservation->starts_at
            ->copy()
            ->timezone($restaurantTimezone)
            ->format('l, F j, Y \a\t g:i a');
    }

    protected function restaurantImageUrl(): ?string
    {
        $featuredMedia = $this->reservation->restaurant->getFirstMedia('featured');

        if ($featuredMedia instanceof Media) {
            return $featuredMedia->getFullUrl();
        }

        $galleryMedia = $this->reservation->restaurant->getFirstMedia('gallery');

        return $galleryMedia instanceof Media ? $galleryMedia->getFullUrl() : null;
    }

    protected function directionsUrl(): ?string
    {
        $restaurant = $this->reservation->restaurant;

        if ($restaurant->latitude !== null && $restaurant->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode($restaurant->latitude.','.$restaurant->longitude);
        }

        $address = trim(implode(', ', array_filter([
            $restaurant->address_line_1,
            $restaurant->address_line_2,
            $restaurant->city,
            $restaurant->state,
            $restaurant->country,
        ])));

        if ($address === '') {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
    }

    protected function addressLineOne(): ?string
    {
        $restaurant = $this->reservation->restaurant;
        $line = trim(implode(', ', array_filter([
            $restaurant->address_line_1,
            $restaurant->address_line_2,
        ])));

        return $line !== '' ? $line : null;
    }

    protected function addressLineTwo(): ?string
    {
        $restaurant = $this->reservation->restaurant;
        $line = trim(implode(', ', array_filter([
            $restaurant->city,
            $restaurant->state,
            $restaurant->country,
        ])));

        return $line !== '' ? $line : null;
    }

    protected function showRestaurantContactDetails(): bool
    {
        return ! in_array($this->action, ['cancelled', 'guest_added', 'upcoming_reminder'], true);
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
