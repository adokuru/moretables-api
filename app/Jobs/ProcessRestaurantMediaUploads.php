<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\MediaLibraryService;
use App\Services\RestaurantMenuSyncService;
use App\Services\RestaurantUploadStagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessRestaurantMediaUploads implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $stagedPayload
     */
    public function __construct(
        public int $restaurantId,
        public array $stagedPayload,
    ) {}

    public function handle(
        MediaLibraryService $mediaLibraryService,
        RestaurantMenuSyncService $restaurantMenuSyncService,
        RestaurantUploadStagingService $stagingService,
    ): void {
        $restaurant = Restaurant::query()->find($this->restaurantId);

        if ($restaurant === null) {
            $stagingService->cleanup($this->stagedPayload);

            return;
        }

        $payload = $stagingService->hydrateUploadedFiles($this->stagedPayload);

        try {
            $mediaLibraryService->syncUploadedMedia($restaurant, $payload);
            $restaurantMenuSyncService->sync($restaurant, $payload);
        } finally {
            $stagingService->cleanup($this->stagedPayload);
        }

        Log::info('admin.restaurant.media.processed', [
            'restaurant_id' => $restaurant->id,
        ]);
    }
}
