<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantShiftService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shifts:backfill-from-schedules {--restaurant= : Limit to a single restaurant ID}')]
#[Description('Seed restaurant shifts from existing meal schedules for restaurants without shifts')]
class BackfillRestaurantShiftsFromSchedules extends Command
{
    public function handle(RestaurantShiftService $shiftService): int
    {
        $restaurantId = $this->option('restaurant');

        $query = Restaurant::query()
            ->whereHas('availabilitySchedules')
            ->whereDoesntHave('shifts');

        if ($restaurantId !== null) {
            $query->whereKey($restaurantId);
        }

        $restaurants = $query->get();

        if ($restaurants->isEmpty()) {
            $this->info('No restaurants require shift backfill.');

            return self::SUCCESS;
        }

        $backfilled = 0;

        foreach ($restaurants as $restaurant) {
            $shiftService->seedFromSchedules($restaurant);
            $backfilled++;
            $this->line("Seeded shifts for restaurant #{$restaurant->id} ({$restaurant->name})");
        }

        $this->info("Backfilled {$backfilled} restaurant(s).");

        return self::SUCCESS;
    }
}
