<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\AvailabilityAlertService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRestaurantAvailabilityAlerts implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $restaurantId,
        public ?CarbonInterface $onDate = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(AvailabilityAlertService $availabilityAlertService): void
    {
        $restaurant = Restaurant::query()->find($this->restaurantId);

        if ($restaurant === null) {
            return;
        }

        $availabilityAlertService->processForRestaurant($restaurant, $this->onDate);
    }
}
