<?php

namespace App\Services;

use App\Enums\RestaurantOnboardingStep;
use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use Illuminate\Support\Collection;

class RestaurantOnboardingService
{
    private const STATUS_COMPLETED = 'completed';

    private const STATUS_IN_PROGRESS = 'in_progress';

    private const STATUS_PENDING = 'pending';

    private const REQUIRED_STEPS = [
        RestaurantOnboardingStep::Basics,
        RestaurantOnboardingStep::Location,
        RestaurantOnboardingStep::Cuisine,
        RestaurantOnboardingStep::Meals,
        RestaurantOnboardingStep::Hours,
    ];

    public function __construct(private readonly PerformanceCacheService $performanceCache) {}

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
        $this->performanceCache->invalidateRestaurant($restaurant->id);
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
        $availabilityPeriodIds = $restaurant->availabilityPeriods()->pluck('id')->all();

        foreach ($schedules as $schedule) {
            abort_unless(
                in_array((int) $schedule['restaurant_meal_type_id'], $availabilityPeriodIds),
                422,
                'Meal type ID '.$schedule['restaurant_meal_type_id'].' does not belong to this restaurant.',
            );
        }

        $restaurant->availabilitySchedules()->delete();

        foreach ($schedules as $schedule) {
            $restaurant->availabilitySchedules()->create([
                'restaurant_meal_type_id' => $schedule['restaurant_meal_type_id'],
                'day_of_week' => $schedule['day_of_week'],
                'opens_at' => $schedule['opens_at'],
                'closes_at' => $schedule['closes_at'],
            ]);
        }
    }

    public function getStatus(Restaurant $restaurant): array
    {
        return $this->buildStatus($restaurant);
    }

    public function syncStatus(Restaurant $restaurant): array
    {
        $status = $this->buildStatus($restaurant->refresh());

        // Once a profile has been published, later edits that temporarily leave a
        // required step incomplete (e.g. removing a meal type) must not silently
        // unpublish it again — only an explicit updateStatus() call may do that.
        $status['is_profile_published'] = $restaurant->is_profile_published || $status['is_profile_published'];

        $restaurant->update([
            'onboarding_progress' => $status['progress'],
            'onboarding_current_step' => $status['current_step'],
            'is_profile_published' => $status['is_profile_published'],
        ]);

        return $status;
    }

    public function updateStatus(Restaurant $restaurant, array $data): array
    {
        if (array_key_exists('last_step', $data)) {
            $restaurant->update(['onboarding_last_step' => $data['last_step']]);
        }

        $status = $this->syncStatus($restaurant);

        if (array_key_exists('is_profile_published', $data)) {
            if ($data['is_profile_published']) {
                abort_unless(
                    $this->isPublishable($status['progress']),
                    422,
                    'Complete all onboarding steps before publishing.',
                );
            }

            $restaurant->update(['is_profile_published' => $data['is_profile_published']]);
            $status['is_profile_published'] = $data['is_profile_published'];
        }

        return $status;
    }

    public function availabilityPeriodBelongsToRestaurant(RestaurantAvailabilityPeriod $availabilityPeriod, Restaurant $restaurant): bool
    {
        return (int) $availabilityPeriod->restaurant_id === (int) $restaurant->id;
    }

    private function buildStatus(Restaurant $restaurant): array
    {
        $restaurant->loadMissing([
            'cuisines',
            'availabilityPeriods.schedules',
            'internalNotes',
            'policy',
        ]);

        $progress = [
            RestaurantOnboardingStep::Basics->value => $this->stepStatus(
                $this->basicsComplete($restaurant),
                $this->basicsStarted($restaurant),
            ),
            RestaurantOnboardingStep::Location->value => $this->stepStatus(
                $this->locationComplete($restaurant),
                $this->locationStarted($restaurant),
            ),
            RestaurantOnboardingStep::Cuisine->value => $this->stepStatus(
                $this->cuisineComplete($restaurant),
                $this->cuisineStarted($restaurant),
            ),
            RestaurantOnboardingStep::Meals->value => $this->stepStatus(
                $this->mealsComplete($restaurant),
                $this->mealsStarted($restaurant),
            ),
            RestaurantOnboardingStep::Hours->value => $this->stepStatus(
                $this->hoursComplete($restaurant),
                $this->hoursStarted($restaurant),
            ),
            RestaurantOnboardingStep::Media->value => $this->stepStatus(
                $this->mediaComplete($restaurant),
                $this->mediaStarted($restaurant),
            ),
            RestaurantOnboardingStep::InternalNotes->value => $this->stepStatus(
                $this->internalNotesComplete($restaurant),
                $this->internalNotesStarted($restaurant),
            ),
            RestaurantOnboardingStep::Policies->value => $this->stepStatus(
                $this->policiesComplete($restaurant),
                $this->policiesStarted($restaurant),
            ),
        ];

        $reviewIsComplete = collect(self::REQUIRED_STEPS)
            ->every(fn (RestaurantOnboardingStep $step): bool => $progress[$step->value] === self::STATUS_COMPLETED);

        $reviewIsStarted = collect($progress)->contains(
            fn (string $status): bool => $status !== self::STATUS_PENDING,
        );

        $progress[RestaurantOnboardingStep::Review->value] = $this->stepStatus($reviewIsComplete, $reviewIsStarted);
        $progress[RestaurantOnboardingStep::Published->value] = $this->stepStatus(
            $reviewIsComplete,
            $reviewIsComplete,
        );

        return [
            'current_step' => $this->currentStep($progress),
            'last_step' => $restaurant->onboarding_last_step,
            'is_profile_published' => $reviewIsComplete,
            'progress' => $progress,
        ];
    }

    private function stepStatus(bool $isComplete, bool $isStarted): string
    {
        if ($isComplete) {
            return self::STATUS_COMPLETED;
        }

        return $isStarted ? self::STATUS_IN_PROGRESS : self::STATUS_PENDING;
    }

    /**
     * @param  array<string, string>  $progress
     */
    private function currentStep(array $progress): ?string
    {
        foreach (self::REQUIRED_STEPS as $step) {
            if (($progress[$step->value] ?? self::STATUS_PENDING) !== self::STATUS_COMPLETED) {
                return $step->value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $progress
     */
    private function isPublishable(array $progress): bool
    {
        return collect(RestaurantOnboardingStep::cases())
            ->reject(fn (RestaurantOnboardingStep $step): bool => $step === RestaurantOnboardingStep::Published)
            ->every(fn (RestaurantOnboardingStep $step): bool => ($progress[$step->value] ?? null) === self::STATUS_COMPLETED);
    }

    private function basicsComplete(Restaurant $restaurant): bool
    {
        return $this->allFieldsFilled($restaurant, ['name', 'email', 'phone', 'description'])
            && mb_strlen((string) $restaurant->description) >= 100;
    }

    private function basicsStarted(Restaurant $restaurant): bool
    {
        return $this->anyFieldFilled($restaurant, ['name', 'email', 'phone', 'description']);
    }

    private function locationComplete(Restaurant $restaurant): bool
    {
        return $this->allFieldsFilled($restaurant, [
            'address_line_1',
            'city',
            'state',
            'country',
            'timezone',
            'latitude',
            'longitude',
        ]);
    }

    private function locationStarted(Restaurant $restaurant): bool
    {
        return $this->anyFieldFilled($restaurant, [
            'address_line_1',
            'city',
            'state',
            'country',
            'timezone',
            'latitude',
            'longitude',
        ]);
    }

    private function cuisineComplete(Restaurant $restaurant): bool
    {
        return filled($restaurant->average_price_range)
            && $restaurant->cuisines->contains(
                fn ($cuisine): bool => (bool) ($cuisine->pivot->is_primary ?? false),
            );
    }

    private function cuisineStarted(Restaurant $restaurant): bool
    {
        return filled($restaurant->average_price_range) || $restaurant->cuisines->isNotEmpty();
    }

    private function mealsComplete(Restaurant $restaurant): bool
    {
        return $restaurant->availabilityPeriods->isNotEmpty();
    }

    private function mealsStarted(Restaurant $restaurant): bool
    {
        return $restaurant->availabilityPeriods->isNotEmpty();
    }

    private function hoursComplete(Restaurant $restaurant): bool
    {
        return $restaurant->availabilityPeriods->contains(
            fn (RestaurantAvailabilityPeriod $availabilityPeriod): bool => $availabilityPeriod->schedules->isNotEmpty(),
        );
    }

    private function hoursStarted(Restaurant $restaurant): bool
    {
        return $restaurant->availabilityPeriods->flatMap(
            fn (RestaurantAvailabilityPeriod $availabilityPeriod): Collection => $availabilityPeriod->schedules,
        )->isNotEmpty();
    }

    private function mediaComplete(Restaurant $restaurant): bool
    {
        return $restaurant->getFirstMedia('featured') !== null;
    }

    private function mediaStarted(Restaurant $restaurant): bool
    {
        return $restaurant->getMedia('gallery')->isNotEmpty() || $this->mediaComplete($restaurant);
    }

    private function internalNotesComplete(Restaurant $restaurant): bool
    {
        return $restaurant->internalNotes->isNotEmpty();
    }

    private function internalNotesStarted(Restaurant $restaurant): bool
    {
        return $this->internalNotesComplete($restaurant);
    }

    private function policiesComplete(Restaurant $restaurant): bool
    {
        return $restaurant->policy !== null;
    }

    private function policiesStarted(Restaurant $restaurant): bool
    {
        return $restaurant->policy !== null;
    }

    /**
     * @param  list<string>  $fields
     */
    private function allFieldsFilled(Restaurant $restaurant, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! filled($restaurant->{$field})) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $fields
     */
    private function anyFieldFilled(Restaurant $restaurant, array $fields): bool
    {
        foreach ($fields as $field) {
            if (filled($restaurant->{$field})) {
                return true;
            }
        }

        return false;
    }
}
