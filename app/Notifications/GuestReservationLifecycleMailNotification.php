<?php

namespace App\Notifications;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestReservationLifecycleMailNotification extends Notification implements ShouldQueue
{
    use Queueable, UsesNotificationQueues;

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
                'title' => $this->title($restaurant->name),
                'subtitle' => $this->subtitle($restaurant->name),
                'guestName' => $this->guestName(),
                'restaurantName' => $restaurant->name,
                'restaurantImageUrl' => $this->restaurantImageUrl(),
                'formattedDate' => $this->formattedDate(),
                'formattedTime' => $this->formattedTime(),
                'partySize' => $this->reservation->party_size,
                'tableInfo' => $this->tableInfo(),
                'confirmationNumber' => $this->reservation->reservation_reference,
                'showRestaurantContactDetails' => $this->showRestaurantContactDetails(),
                'addressLineOne' => $restaurant->address_line_1,
                'addressLineTwo' => $this->addressLineTwo(),
                'restaurantPhone' => $restaurant->phone,
                'menuUrl' => $restaurant->menu_link,
                'directionsUrl' => $this->directionsUrl(),
                'extraBody' => $this->extraBody(),
                'showReservationActions' => $this->showReservationActions(),
                'showNewReservationButton' => $this->showNewReservationButton(),
                'calendarUrl' => $this->calendarUrl(),
                'modifyUrl' => $this->manageReservationUrl(),
                'cancelUrl' => $this->manageReservationUrl(),
                'newReservationUrl' => $this->newReservationUrl(),
                'footerLink1Url' => $this->frontendBaseUrl(),
                'footerLink1Label' => 'Earn rewards',
                'footerLink2Url' => $this->frontendBaseUrl().'/unsubscribe',
                'footerLink2Label' => 'Unsubscribe',
            ],
        );
    }

    protected function title(string $restaurantName): string
    {
        return match ($this->action) {
            'created' => 'Reservation confirmed',
            'updated' => 'Reservation changed',
            'cancelled' => 'Reservation canceled',
            'guest_added' => 'You have been added to the below reservation',
            'upcoming_reminder' => "Your reservation is coming up at {$restaurantName} in {$this->daysUntilReservationLabel()}",
            default => 'Reservation update',
        };
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

    protected function subtitle(string $restaurantName): string
    {
        return match ($this->action) {
            'created' => 'Thanks for using MoreTables',
            'updated' => 'Here are the new details:',
            'guest_added', 'upcoming_reminder' => 'Here are the details',
            'cancelled' => "You've successfully canceled your reservation at {$restaurantName}.",
            default => 'There is an update to your reservation.',
        };
    }

    protected function extraBody(): ?string
    {
        return match ($this->action) {
            'created' => 'You can manage or update your reservation anytime',
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

    protected function tableInfo(): string
    {
        if ($this->reservation->starts_at === null) {
            return "Table for {$this->reservation->party_size}";
        }

        $restaurantTimezone = $this->reservation->restaurant->timezone ?: config('app.timezone');
        $startsAt = $this->reservation->starts_at->copy()->timezone($restaurantTimezone);

        return sprintf(
            'Table for %d on %s at %s',
            $this->reservation->party_size,
            $startsAt->format('l, F j, Y'),
            $startsAt->format('g:i a'),
        );
    }

    protected function restaurantImageUrl(): ?string
    {
        $url = $this->reservation->restaurant->getFirstMediaUrl('featured', 'thumb');

        return $url !== '' ? $url : null;
    }

    protected function showReservationActions(): bool
    {
        return in_array($this->action, ['created', 'updated'], true);
    }

    protected function showNewReservationButton(): bool
    {
        return $this->action === 'cancelled';
    }

    protected function frontendBaseUrl(): string
    {
        return rtrim(config('app.frontend_urls.main') ?: config('app.url'), '/');
    }

    protected function manageReservationUrl(): string
    {
        $slug = $this->reservation->restaurant->slug;

        if ($slug === null || $slug === '') {
            return $this->frontendBaseUrl();
        }

        return $this->frontendBaseUrl().'/restaurants/'.$slug.'/reservations?reservation_id='.$this->reservation->id;
    }

    protected function calendarUrl(): string
    {
        $start = $this->reservation->starts_at;

        if ($start === null) {
            return $this->manageReservationUrl();
        }

        $end = $this->reservation->ends_at ?? $start->copy()->addHours(2);

        $params = [
            'action' => 'TEMPLATE',
            'text' => 'Reservation at '.$this->reservation->restaurant->name,
            'dates' => $start->copy()->utc()->format('Ymd\THis\Z').'/'.$end->copy()->utc()->format('Ymd\THis\Z'),
            'details' => 'Confirmation #: '.$this->reservation->reservation_reference,
            'location' => $this->restaurantAddressQuery() ?? '',
        ];

        return 'https://calendar.google.com/calendar/render?'.http_build_query($params);
    }

    protected function newReservationUrl(): string
    {
        $slug = $this->reservation->restaurant->slug;

        return $slug !== null && $slug !== ''
            ? $this->frontendBaseUrl().'/restaurants/'.$slug
            : $this->frontendBaseUrl();
    }

    protected function showRestaurantContactDetails(): bool
    {
        return in_array($this->action, ['created', 'updated'], true);
    }

    protected function addressLineTwo(): ?string
    {
        $restaurant = $this->reservation->restaurant;
        $parts = array_filter([
            $restaurant->address_line_2,
            $restaurant->city,
            $restaurant->state,
            $restaurant->country,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    protected function directionsUrl(): ?string
    {
        $restaurant = $this->reservation->restaurant;

        if ($restaurant->latitude !== null && $restaurant->longitude !== null) {
            $query = number_format((float) $restaurant->latitude, 7, '.', '').','.number_format((float) $restaurant->longitude, 7, '.', '');
        } else {
            $query = $this->restaurantAddressQuery();
        }

        if ($query === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($query);
    }

    protected function restaurantAddressQuery(): ?string
    {
        $restaurant = $this->reservation->restaurant;
        $parts = array_filter([
            $restaurant->name,
            $restaurant->address_line_1,
            $restaurant->address_line_2,
            $restaurant->city,
            $restaurant->state,
            $restaurant->country,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
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
