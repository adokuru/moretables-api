<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\ReservationCardHoldService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ChargeNoShowFeeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reservationId) {}

    public function handle(ReservationCardHoldService $cardHoldService): void
    {
        $reservation = Reservation::query()->with('cardHold')->find($this->reservationId);

        if ($reservation === null) {
            return;
        }

        $cardHoldService->chargeNoShow($reservation);
    }
}
