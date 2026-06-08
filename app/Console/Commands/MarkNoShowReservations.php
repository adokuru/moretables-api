<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\ReservationStatus;
use App\Services\ReservationService;
use App\Services\SystemUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MarkNoShowReservations extends Command
{
    protected $signature = 'app:mark-no-show-reservations';

    protected $description = 'Mark overdue reservations as no-show after the configured grace period';

    public function __construct(
        protected ReservationService $reservationService,
        protected SystemUserService $systemUserService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $graceMinutes = (int) config('reservations.no_show_grace_minutes', 60);
        $eligibleStatuses = config('reservations.no_show_eligible_statuses', []);

        if ($graceMinutes < 1 || $eligibleStatuses === []) {
            $this->error('No-show automation is misconfigured.');

            return self::FAILURE;
        }

        $actor = $this->systemUserService->forNoShowAutomation();
        $cutoff = Carbon::now()->subMinutes($graceMinutes);
        $marked = 0;
        $markedIds = [];

        Reservation::query()
            ->whereIn('status', $eligibleStatuses)
            ->where('starts_at', '<=', $cutoff)
            ->orderBy('id')
            ->each(function (Reservation $reservation) use ($actor, &$marked, &$markedIds): void {
                $previousStatus = $reservation->status;

                $updated = $this->reservationService->noShowReservation($reservation, $actor, automated: true);

                if ($updated->status === ReservationStatus::NoShow && $previousStatus !== ReservationStatus::NoShow) {
                    $marked++;
                    $markedIds[] = $updated->id;
                }
            });

        if ($marked > 0) {
            Log::info('Automated no-show reservations marked.', [
                'count' => $marked,
                'reservation_ids' => $markedIds,
                'grace_minutes' => $graceMinutes,
                'cutoff' => $cutoff->toIso8601String(),
            ]);
        }

        $this->info("Marked {$marked} reservation(s) as no-show.");

        return self::SUCCESS;
    }
}
