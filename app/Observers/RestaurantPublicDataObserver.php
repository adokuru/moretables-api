<?php

namespace App\Observers;

use App\Models\BillingPlan;
use App\Models\CuisineOption;
use App\Models\MerchantSubscription;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantReview;
use App\Services\PerformanceCacheService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RestaurantPublicDataObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly PerformanceCacheService $performanceCache) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $this->performanceCache->bump('admin-dashboard');

        if ($model instanceof CuisineOption) {
            $this->performanceCache->invalidateCuisines();

            return;
        }

        if ($model instanceof BillingPlan) {
            $this->performanceCache->bump('billing-plans');

            return;
        }

        if ($model instanceof RestaurantReview) {
            $this->performanceCache->bump('random-reviews');
        }

        $restaurantId = match (true) {
            $model instanceof Restaurant => $model->id,
            $model instanceof Media && $model->model_type === Restaurant::class => $model->model_id,
            $model instanceof Media && $model->model_type === RestaurantMenuItem::class => RestaurantMenuItem::query()
                ->whereKey($model->model_id)
                ->value('restaurant_id'),
            default => $model->getAttribute('restaurant_id'),
        };

        if ($restaurantId) {
            $this->performanceCache->invalidateRestaurant((int) $restaurantId);
        }

        if ($model instanceof MerchantSubscription) {
            if ($restaurantId) {
                $this->performanceCache->invalidateBillingEligibility((int) $restaurantId);

                return;
            }

            // A business-level subscription decides eligibility for every restaurant it owns.
            if ($model->organization_id) {
                $this->performanceCache->invalidateBusinessBillingEligibility((int) $model->organization_id);
            }
        }
    }
}
