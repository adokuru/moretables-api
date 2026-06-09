<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityAlertService;
use App\WaitlistStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:process-availability-alerts')]
#[Description('Notify guests when a table opens for an active availability alert, and expire elapsed alerts')]
class ProcessAvailabilityAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AvailabilityAlertService $availabilityAlertService): int
    {
        $expiredCount = $availabilityAlertService->expireElapsed();

        $restaurantIds = WaitlistEntry::query()
            ->availabilityAlerts()
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Notified->value])
            ->where('preferred_ends_at', '>', now())
            ->distinct()
            ->pluck('restaurant_id');

        $notifiedCount = 0;

        Restaurant::query()
            ->whereIn('id', $restaurantIds)
            ->each(function (Restaurant $restaurant) use ($availabilityAlertService, &$notifiedCount): void {
                $notifiedCount += $availabilityAlertService->processForRestaurant($restaurant);
            });

        $this->info("Expired {$expiredCount} elapsed alert(s); notified {$notifiedCount} guest(s).");

        return self::SUCCESS;
    }
}
