<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantSpecialDay;
use App\Models\RestaurantTable;
use App\ReservationStatus;
use App\TableStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class AvailabilityService
{
    public function calculateEndTime(Restaurant $restaurant, CarbonInterface $startsAt): Carbon
    {
        return Carbon::parse($startsAt)->addMinutes($restaurant->policy?->reservation_duration_minutes ?? 120);
    }

    public function isBookableAt(Restaurant $restaurant, CarbonInterface $startsAt): bool
    {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $endsAt = $this->calculateEndTime($restaurant, $localStartsAt);

        return collect($this->effectiveTimeWindows($restaurant, $localStartsAt->toDateString()))
            ->contains(fn (array $window): bool => $localStartsAt->greaterThanOrEqualTo($window[0])
                && $endsAt->lessThanOrEqualTo($window[1]));
    }

    public function findAvailableTable(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
        ?int $diningAreaId = null,
    ): ?RestaurantTable {
        return $this->availableTables($restaurant, $startsAt, $partySize, $excludingReservationId, $diningAreaId)->first();
    }

    public function availableTables(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
        ?int $diningAreaId = null,
    ): Collection {
        $endsAt = $this->calculateEndTime($restaurant, $startsAt);

        $blockedTableIds = $this->overlappingReservationsQuery($restaurant, $startsAt, $endsAt, $excludingReservationId)
            ->pluck('restaurant_table_id');

        return $this->eligibleTablesQuery($restaurant, $partySize)
            ->when($diningAreaId, fn ($query) => $query->where('dining_area_id', $diningAreaId))
            ->whereNotIn('id', $blockedTableIds)
            ->get();
    }

    public function isTableAvailable(
        Restaurant $restaurant,
        RestaurantTable $table,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): bool {
        if ($table->restaurant_id !== $restaurant->id
            || ! $table->is_active
            || $table->status === TableStatus::Unavailable
            || $table->max_capacity < $partySize
            || ($partySize > 1 && $table->min_capacity > $partySize)) {
            return false;
        }

        return ! $this->overlappingReservationsQuery(
            restaurant: $restaurant,
            startsAt: $startsAt,
            endsAt: $this->calculateEndTime($restaurant, $startsAt),
            excludingReservationId: $excludingReservationId,
        )
            ->where('restaurant_table_id', $table->id)
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAvailableSlots(
        Restaurant $restaurant,
        string $date,
        int $partySize,
        ?string $requesterTimezone = null,
    ): array {
        $windows = $this->effectiveTimeWindows($restaurant, $date);

        if (empty($windows)) {
            return [];
        }

        $tables = $this->eligibleTablesQuery($restaurant, $partySize)->get();

        if ($tables->isEmpty()) {
            return [];
        }

        $rangeStartsAt = collect($windows)->min(fn (array $window): Carbon => $window[0])->copy()->utc();
        $rangeEndsAt = collect($windows)->max(fn (array $window): Carbon => $window[1])->copy()->utc();

        $reservationsByTable = $this->overlappingReservationsQuery($restaurant, $rangeStartsAt, $rangeEndsAt)
            ->get(['restaurant_table_id', 'starts_at', 'ends_at'])
            ->groupBy('restaurant_table_id');

        return $this->generateSlots(
            restaurant: $restaurant,
            windows: $windows,
            tables: $tables,
            reservationsByTable: $reservationsByTable,
            requesterTimezone: $requesterTimezone,
        );
    }

    /**
     * @param  SupportCollection<int, Restaurant>  $restaurants
     * @return array<int, list<array<string, mixed>>>
     */
    public function listAvailableSlotsForRestaurants(
        SupportCollection $restaurants,
        string $date,
        int $partySize,
        ?string $requesterTimezone = null,
    ): array {
        $restaurants = $restaurants->unique('id')->values();
        $slotsByRestaurant = $restaurants
            ->mapWithKeys(fn (Restaurant $restaurant): array => [$restaurant->id => []])
            ->all();

        if ($restaurants->isEmpty()) {
            return $slotsByRestaurant;
        }

        $restaurantIds = $restaurants->pluck('id')->all();
        $specialDaysByRestaurant = RestaurantSpecialDay::query()
            ->with('shifts')
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('date', $date)
            ->get()
            ->groupBy('restaurant_id');

        $restaurants->each(function (Restaurant $restaurant) use ($specialDaysByRestaurant): void {
            $restaurant->setRelation(
                'specialDays',
                new Collection($specialDaysByRestaurant->get($restaurant->id, collect())->all()),
            );
        });

        $windowsByRestaurant = $restaurants
            ->mapWithKeys(fn (Restaurant $restaurant): array => [
                $restaurant->id => $this->effectiveTimeWindows($restaurant, $date),
            ])
            ->filter();

        if ($windowsByRestaurant->isEmpty()) {
            return $slotsByRestaurant;
        }

        $bookableRestaurantIds = $windowsByRestaurant->keys()->all();
        $tablesByRestaurant = $this->eligibleTablesForRestaurantsQuery($bookableRestaurantIds, $partySize)
            ->get()
            ->groupBy('restaurant_id');
        $rangeStartsAt = $windowsByRestaurant
            ->flatten(1)
            ->min(fn (array $window): Carbon => $window[0])
            ->copy()
            ->utc();
        $rangeEndsAt = $windowsByRestaurant
            ->flatten(1)
            ->max(fn (array $window): Carbon => $window[1])
            ->copy()
            ->utc();
        $reservationsByRestaurant = $this->overlappingReservationsForRestaurantsQuery(
            $bookableRestaurantIds,
            $rangeStartsAt,
            $rangeEndsAt,
        )
            ->get(['restaurant_id', 'restaurant_table_id', 'starts_at', 'ends_at'])
            ->groupBy('restaurant_id');

        foreach ($restaurants as $restaurant) {
            $windows = $windowsByRestaurant->get($restaurant->id, []);
            $tables = $tablesByRestaurant->get($restaurant->id, collect());

            if (empty($windows) || $tables->isEmpty()) {
                continue;
            }

            $slotsByRestaurant[$restaurant->id] = $this->generateSlots(
                restaurant: $restaurant,
                windows: $windows,
                tables: $tables,
                reservationsByTable: $reservationsByRestaurant->get($restaurant->id, collect())->groupBy('restaurant_table_id'),
                requesterTimezone: $requesterTimezone,
            );
        }

        return $slotsByRestaurant;
    }

    /**
     * @return array{starts_at: string, ends_at: string}|null
     */
    public function timeWindowForMealType(Restaurant $restaurant, string $date, int $availabilityPeriodId): ?array
    {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localDate = Carbon::createFromFormat('Y-m-d', $date, $restaurantTimezone);
        $specialDay = $this->specialDayForDate($restaurant, $localDate->toDateString());

        if ($specialDay !== null) {
            if ($specialDay->is_closed) {
                return null;
            }

            $shift = $specialDay->shifts
                ->sortBy('opens_at')
                ->firstWhere('restaurant_meal_type_id', $availabilityPeriodId);

            return $shift ? ['starts_at' => $shift->opens_at, 'ends_at' => $shift->closes_at] : null;
        }

        $schedule = $restaurant->availabilitySchedules()
            ->where('restaurant_meal_type_id', $availabilityPeriodId)
            ->where('day_of_week', $localDate->dayOfWeek)
            ->orderBy('opens_at')
            ->first();

        return $schedule ? ['starts_at' => $schedule->opens_at, 'ends_at' => $schedule->closes_at] : null;
    }

    /**
     * @return list<array{Carbon, Carbon}>
     */
    public function effectiveTimeWindows(Restaurant $restaurant, string $date): array
    {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localDate = Carbon::createFromFormat('Y-m-d', $date, $restaurantTimezone);

        return $this->buildTimeWindows(
            restaurant: $restaurant,
            dayOfWeek: (int) $localDate->dayOfWeek,
            dateStr: $localDate->toDateString(),
            timezone: $restaurantTimezone,
        );
    }

    /**
     * @param  list<array{Carbon, Carbon}>  $windows
     * @param  SupportCollection<int, RestaurantTable>  $tables
     * @param  SupportCollection<int, SupportCollection<int, Reservation>>  $reservationsByTable
     * @return list<array<string, mixed>>
     */
    private function generateSlots(
        Restaurant $restaurant,
        array $windows,
        SupportCollection $tables,
        SupportCollection $reservationsByTable,
        ?string $requesterTimezone = null,
    ): array {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $displayTimezone = $requesterTimezone ?: $restaurantTimezone;
        $duration = $restaurant->policy?->reservation_duration_minutes ?? 120;
        $now = Carbon::now();
        $slots = [];

        foreach ($windows as [$opensAt, $closesAt]) {
            $cursor = $opensAt->copy();

            while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($closesAt)) {
                if ($cursor->lte($now)) {
                    $cursor->addMinutes(15);

                    continue;
                }

                $utcCursor = $cursor->copy()->utc();
                $endsAt = $utcCursor->copy()->addMinutes($duration);
                $table = $tables->first(function (RestaurantTable $table) use ($endsAt, $reservationsByTable, $utcCursor): bool {
                    return $reservationsByTable
                        ->get($table->id, collect())
                        ->doesntContain(fn (Reservation $reservation): bool => $reservation->starts_at->lt($endsAt)
                            && $reservation->ends_at->gt($utcCursor));
                });

                if ($table) {
                    $localCursor = $utcCursor->copy()->setTimezone($displayTimezone);
                    $slots[$utcCursor->toIso8601String()] = [
                        'starts_at' => $utcCursor->toIso8601String(),
                        'ends_at' => $endsAt->toIso8601String(),
                        'local_starts_at' => $localCursor->toIso8601String(),
                        'local_ends_at' => $localCursor->copy()->addMinutes($duration)->toIso8601String(),
                        'table_id' => $table->id,
                    ];
                }

                $cursor->addMinutes(15);
            }
        }

        return array_values($slots);
    }

    /**
     * @return Builder<RestaurantTable>
     */
    private function eligibleTablesQuery(Restaurant $restaurant, int $partySize): Builder
    {
        return $this->eligibleTablesForRestaurantsQuery([$restaurant->id], $partySize);
    }

    /**
     * @param  list<int>  $restaurantIds
     * @return Builder<RestaurantTable>
     */
    private function eligibleTablesForRestaurantsQuery(array $restaurantIds, int $partySize): Builder
    {
        return RestaurantTable::query()
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('is_active', true)
            ->when($partySize > 1, fn (Builder $query) => $query->where('min_capacity', '<=', $partySize))
            ->where('max_capacity', '>=', $partySize)
            ->where('status', '!=', TableStatus::Unavailable->value)
            ->orderBy('max_capacity')
            ->orderBy('name');
    }

    /**
     * @return Builder<Reservation>
     */
    private function overlappingReservationsQuery(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $excludingReservationId = null,
    ): Builder {
        return $this->overlappingReservationsForRestaurantsQuery([$restaurant->id], $startsAt, $endsAt)
            ->when($excludingReservationId, fn (Builder $query) => $query->whereKeyNot($excludingReservationId));
    }

    /**
     * @param  list<int>  $restaurantIds
     * @return Builder<Reservation>
     */
    private function overlappingReservationsForRestaurantsQuery(
        array $restaurantIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Builder {
        return Reservation::query()
            ->whereIn('restaurant_id', $restaurantIds)
            ->whereNotNull('restaurant_table_id')
            ->whereIn('status', [
                ReservationStatus::Booked->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Arrived->value,
                ReservationStatus::Seated->value,
            ])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    /**
     * Build [(opensAt, closesAt), ...] windows for the day.
     * Uses meal schedules when configured, otherwise falls back to legacy restaurant_hours.
     *
     * @return list<array{Carbon, Carbon}>
     */
    private function buildTimeWindows(Restaurant $restaurant, int $dayOfWeek, string $dateStr, string $timezone): array
    {
        $specialDay = $this->specialDayForDate($restaurant, $dateStr);

        if ($specialDay !== null) {
            if ($specialDay->is_closed) {
                return [];
            }

            return $specialDay->shifts
                ->sortBy('opens_at')
                ->map(fn ($shift) => [
                    Carbon::parse($dateStr.' '.$shift->opens_at, $timezone),
                    Carbon::parse($dateStr.' '.$shift->closes_at, $timezone),
                ])
                ->values()
                ->all();
        }

        $availabilitySchedules = $restaurant->relationLoaded('availabilitySchedules')
            ? $restaurant->availabilitySchedules->where('day_of_week', $dayOfWeek)->sortBy('opens_at')->values()
            : $restaurant->availabilitySchedules()->where('day_of_week', $dayOfWeek)->orderBy('opens_at')->get();

        if ($availabilitySchedules->isNotEmpty()) {
            return $availabilitySchedules->map(fn ($s) => [
                Carbon::parse($dateStr.' '.$s->opens_at, $timezone),
                Carbon::parse($dateStr.' '.$s->closes_at, $timezone),
            ])->all();
        }

        // Fall back to legacy single-window restaurant_hours
        $hours = $restaurant->hours->firstWhere('day_of_week', $dayOfWeek);

        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return [];
        }

        return [[
            Carbon::parse($dateStr.' '.$hours->opens_at, $timezone),
            Carbon::parse($dateStr.' '.$hours->closes_at, $timezone),
        ]];
    }

    /**
     * Resolve the special day override for the given date, if one exists.
     */
    private function specialDayForDate(Restaurant $restaurant, string $dateStr): ?RestaurantSpecialDay
    {
        if ($restaurant->relationLoaded('specialDays')) {
            $specialDay = $restaurant->specialDays
                ->first(fn (RestaurantSpecialDay $day): bool => $day->date->format('Y-m-d') === $dateStr);

            return $specialDay?->loadMissing('shifts');
        }

        return $restaurant->specialDays()
            ->with('shifts')
            ->where('date', $dateStr)
            ->first();
    }
}
