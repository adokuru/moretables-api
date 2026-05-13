<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantMealType;

class RestaurantOnboardingService
{
    public function syncCuisines(Restaurant $restaurant, ?int $primaryId, array $additionalIds = []): void
    {
        if ($primaryId === null) {
            return;
        }

        $pivot = [$primaryId => ['is_primary' => true]];

        foreach ($additionalIds as $id) {
            if ($id !== $primaryId) {
                $pivot[$id] = ['is_primary' => false];
            }
        }

        $restaurant->cuisines()->sync($pivot);
    }

    public function syncSocialHandles(Restaurant $restaurant, array $handles): void
    {
        $platforms = array_column($handles, 'platform');

        $restaurant->socialHandles()->whereNotIn('platform', $platforms)->delete();

        foreach ($handles as $item) {
            $restaurant->socialHandles()->updateOrCreate(
                ['platform' => $item['platform']],
                ['handle' => $item['handle']],
            );
        }
    }

    public function replaceMealSchedules(Restaurant $restaurant, array $schedules): void
    {
        $mealTypeIds = $restaurant->mealTypes()->pluck('id')->all();

        foreach ($schedules as $schedule) {
            abort_unless(
                in_array((int) $schedule['restaurant_meal_type_id'], $mealTypeIds),
                422,
                'Meal type ID '.$schedule['restaurant_meal_type_id'].' does not belong to this restaurant.',
            );
        }

        $restaurant->mealSchedules()->delete();

        foreach ($schedules as $schedule) {
            $restaurant->mealSchedules()->create([
                'restaurant_meal_type_id' => $schedule['restaurant_meal_type_id'],
                'day_of_week' => $schedule['day_of_week'],
                'opens_at' => $schedule['opens_at'],
                'closes_at' => $schedule['closes_at'],
            ]);
        }
    }

    public function getStatus(Restaurant $restaurant): array
    {
        return [
            'current_step' => $restaurant->onboarding_current_step,
            'is_profile_published' => (bool) $restaurant->is_profile_published,
            'progress' => $restaurant->onboarding_progress ?? (object) [],
        ];
    }

    public function updateStatus(Restaurant $restaurant, array $data): array
    {
        $progress = $restaurant->onboarding_progress ?? [];

        if (isset($data['step'], $data['step_status'])) {
            $progress[$data['step']] = $data['step_status'];
        }

        $restaurant->update([
            'onboarding_progress' => $progress,
            'onboarding_current_step' => $data['current_step'] ?? $restaurant->onboarding_current_step,
            'is_profile_published' => $data['is_profile_published'] ?? $restaurant->is_profile_published,
        ]);

        return $this->getStatus($restaurant->refresh());
    }

    public function mealTypeBelongsToRestaurant(RestaurantMealType $mealType, Restaurant $restaurant): bool
    {
        return (int) $mealType->restaurant_id === (int) $restaurant->id;
    }
}
