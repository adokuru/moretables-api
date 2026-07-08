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
            return;
        }

        if (! $this->senderIsPrimaryBooker($reservation)) {
            Log::warning('WhatsApp cancel request rejected: sender is not the primary booker.', [
                'reservation_id' => $reservation->id,
            ]);

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

        $bookerPhone = PhoneNumber::forWhatsApp(
            (string) ($reservation->user?->phone ?? $reservation->guestContact?->phone),
        );

        return $bookerPhone !== '' && hash_equals($bookerPhone, $from);
    }
}
