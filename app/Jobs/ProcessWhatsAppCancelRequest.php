<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\ReservationStatus;
use App\Services\ReservationService;
use App\Services\WhatsAppService;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppCancelRequest implements ShouldQueue
{
    use Queueable;

    /**
     * Reply used whenever the request cannot be tied to a booking the sender
     * owns. Deliberately carries no reservation details so a stray or guessed
     * payload cannot confirm that a given reservation exists.
     */
    protected const UNVERIFIED_REPLY = 'We could not verify that cancellation request. Please contact the restaurant directly to cancel your reservation.';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $reservationId,
        public string $fromPhone,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(ReservationService $reservationService, WhatsAppService $whatsAppService): void
    {
        $reservation = Reservation::query()
            ->with(['restaurant.policy', 'user', 'guestContact'])
            ->find($this->reservationId);

        if ($reservation === null) {
            Log::warning('WhatsApp cancel request rejected: reservation not found.', [
                'reservation_id' => $this->reservationId,
                'from' => PhoneNumber::mask($this->fromPhone),
            ]);

            $whatsAppService->sendText($this->fromPhone, self::UNVERIFIED_REPLY);

            return;
        }

        if (! $this->senderIsPrimaryBooker($reservation)) {
            Log::warning('WhatsApp cancel request rejected: sender is not the primary booker.', [
                'reservation_id' => $reservation->id,
                'from' => PhoneNumber::mask($this->fromPhone),
                'booker_candidates' => array_map(
                    static fn (string $phone): string => PhoneNumber::mask($phone),
                    $this->bookerPhones($reservation),
                ),
            ]);

            $whatsAppService->sendText($this->fromPhone, self::UNVERIFIED_REPLY);

            return;
        }

        $restaurantName = $reservation->restaurant->name;

        if ($reservation->status === ReservationStatus::Cancelled) {
            $whatsAppService->sendText($this->fromPhone, "Your reservation at {$restaurantName} ({$reservation->reservation_reference}) has already been cancelled.");

            return;
        }

        if (! in_array($reservation->status, [ReservationStatus::Booked, ReservationStatus::Confirmed], true)) {
            $whatsAppService->sendText($this->fromPhone, "Your reservation at {$restaurantName} ({$reservation->reservation_reference}) can no longer be cancelled. Please contact the restaurant.");

            return;
        }

        $cutoffHours = $reservation->restaurant->policy?->cancellation_cutoff_hours ?? 1;

        if ($reservation->starts_at === null || Carbon::parse($reservation->starts_at)->subHours($cutoffHours)->isPast()) {
            $whatsAppService->sendText($this->fromPhone, "Your reservation at {$restaurantName} ({$reservation->reservation_reference}) is within the cancellation window and can no longer be cancelled here. Please contact the restaurant.");

            return;
        }

        $reservationService->cancelReservation($reservation, null);

        $whatsAppService->sendText($this->fromPhone, "Your reservation at {$restaurantName} ({$reservation->reservation_reference}) has been cancelled.");
    }

    /**
     * Only the primary booker may cancel from chat. Comparison uses digits-only
     * phone numbers because Meta sends `from` without the leading plus sign.
     */
    protected function senderIsPrimaryBooker(Reservation $reservation): bool
    {
        $from = PhoneNumber::forWhatsApp($this->fromPhone);

        if ($from === '') {
            return false;
        }

        foreach ($this->bookerPhones($reservation) as $bookerPhone) {
            if (hash_equals($bookerPhone, $from)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every number that may authorize a chat cancellation. A reservation can
     * carry both a registered user and a guest contact, and the cancel button
     * is offered to whichever of the two the template was addressed to, so
     * either number has to be accepted.
     *
     * @return list<string>
     */
    protected function bookerPhones(Reservation $reservation): array
    {
        return collect([$reservation->user?->phone, $reservation->guestContact?->phone])
            ->map(static fn (?string $phone): string => PhoneNumber::forWhatsApp((string) $phone))
            ->filter(static fn (string $phone): bool => $phone !== '')
            ->unique()
            ->values()
            ->all();
    }
}
