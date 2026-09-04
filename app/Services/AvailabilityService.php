<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
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
    public function __construct(protected RestaurantShiftService $restaurantShiftService) {}

    public function calculateEndTime(Restaurant $restaurant, CarbonInterface $startsAt, ?int $partySize = null): Carbon
    {
        $parsedStartsAt = Carbon::parse($startsAt);
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = $parsedStartsAt->copy()->setTimezone($restaurantTimezone);
        $fallbackDuration = $restaurant->policy?->reservation_duration_minutes ?? 120;
        $shift = $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localStartsAt);
        $duration = $fallbackDuration;

        if ($shift !== null && $partySize !== null) {
            $duration = $this->restaurantShiftService->turnDurationForPartySize($shift, $partySize, $fallbackDuration);
        }

        return $localStartsAt->copy()->addMinutes($duration)->setTimezone($parsedStartsAt->timezone);
    }

    public function isBookableAt(Restaurant $restaurant, CarbonInterface $startsAt, ?int $partySize = null): bool
    {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $endsAt = $this->calculateEndTime($restaurant, $localStartsAt, $partySize);

        if ($this->specialDayForDate($restaurant, $localStartsAt->toDateString())?->is_closed) {
            return false;
        }

        return collect([
            ...$this->effectiveTimeWindows($restaurant, $localStartsAt->toDateString()),
            ...$this->effectiveTimeWindows($restaurant, $localStartsAt->copy()->subDay()->toDateString()),
        ])
            ->contains(fn (array $window): bool => $localStartsAt->greaterThanOrEqualTo($this->windowOpens($window))
                && $endsAt->lessThanOrEqualTo($this->windowCloses($window)));
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
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $endsAt = $this->calculateEndTime($restaurant, $localStartsAt, $partySize);
        $shift = $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localStartsAt);

        $blockedTableIds = $this->assignedTableIds(
            $this->overlappingReservationsQuery($restaurant, $startsAt, $endsAt, $excludingReservationId)
                ->with('assignedTables:id')
                ->get(['id', 'restaurant_table_id']),
        );

        $tables = $this->eligibleTablesQuery($restaurant, $partySize)
            ->when($diningAreaId, fn ($query) => $query->where('dining_area_id', $diningAreaId))
            ->whereNotIn('id', $blockedTableIds)
            ->get();

        if ($shift !== null) {
            if (! $this->restaurantShiftService->isPartySizeReleased($shift, $localStartsAt, $partySize)) {
                return new Collection;
            }

            $tables = $this->restaurantShiftService
                ->filterTablesByShiftAvailability($shift, $tables, $partySize);

            $tables = $tables->filter(
                fn (RestaurantTable $table): bool => $this->restaurantShiftService
                    ->isTableReleased($shift, $table, $localStartsAt),
            )->values();
        } elseif ($this->restaurantUsesWeeklyShifts($restaurant)) {
            return new Collection;
        }

        return new Collection($tables->all());
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
            || in_array($table->status, [TableStatus::Unavailable, TableStatus::Cleaning], true)
            || $table->max_capacity < $partySize
            || ($partySize > 1 && $table->min_capacity > $partySize)) {
            return false;
        }

        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $shift = $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localStartsAt);

        if ($shift !== null) {
            if (! $this->restaurantShiftService->isPartySizeReleased($shift, $localStartsAt, $partySize)) {
                return false;
            }

            $filtered = $this->restaurantShiftService
                ->filterTablesByShiftAvailability($shift, collect([$table]), $partySize);

            if ($filtered->isEmpty()) {
                return false;
            }

            if (! $this->restaurantShiftService->isTableReleased($shift, $table, $localStartsAt)) {
                return false;
            }
        } elseif ($this->restaurantUsesWeeklyShifts($restaurant)) {
            return false;
        }

        return ! $this->overlappingReservationsQuery(
            restaurant: $restaurant,
            startsAt: $startsAt,
            endsAt: $this->calculateEndTime($restaurant, $startsAt, $partySize),
            excludingReservationId: $excludingReservationId,
        )
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->exists();
    }

    public function isCombinationMemberAvailable(
        Restaurant $restaurant,
        RestaurantTable $table,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): bool {
        if ($table->restaurant_id !== $restaurant->id
            || ! $table->is_active
            || in_array($table->status, [TableStatus::Unavailable, TableStatus::Cleaning], true)) {
            return false;
        }

        if ($table->status === TableStatus::Occupied
            && (! $excludingReservationId || ! Reservation::query()
                ->whereKey($excludingReservationId)
                ->where('restaurant_id', $restaurant->id)
                ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
                ->exists())) {
            return false;
        }

        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $shift = $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localStartsAt);

        if ($shift !== null) {
            if (! $this->restaurantShiftService->isPartySizeReleased($shift, $localStartsAt, $partySize)
                || $this->restaurantShiftService->filterTablesByShiftAvailability($shift, collect([$table]), $partySize)->isEmpty()
                || ! $this->restaurantShiftService->isTableReleased($shift, $table, $localStartsAt)) {
                return false;
            }
        } elseif ($this->restaurantUsesWeeklyShifts($restaurant)) {
            return false;
        }

        $seated = Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', ReservationStatus::Seated->value)
            ->when($excludingReservationId, fn (Builder $query) => $query->whereKeyNot($excludingReservationId))
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->exists();

        if ($seated) {
            return false;
        }

        return ! $this->overlappingReservationsQuery(
            restaurant: $restaurant,
            startsAt: $startsAt,
            endsAt: $this->calculateEndTime($restaurant, $startsAt, $partySize),
            excludingReservationId: $excludingReservationId,
        )
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->exists();
    }

    public function combinationCandidateTables(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): Collection {
        return $restaurant->tables()
            ->where('is_active', true)
            ->whereNotIn('status', [TableStatus::Unavailable->value, TableStatus::Cleaning->value])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (RestaurantTable $table): bool => $this->isCombinationMemberAvailable(
                $restaurant,
                $table,
                $startsAt,
                $partySize,
                $excludingReservationId,
            ))
            ->values();
    }

    public function explainCombinationMemberUnavailable(
        Restaurant $restaurant,
        RestaurantTable $table,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): string|Reservation {
        if (! $table->is_active || $table->status === TableStatus::Unavailable) {
            return 'This table has been marked unavailable by staff.';
        }

        if ($table->status === TableStatus::Cleaning) {
            return Reservation::query()
                ->with(['user', 'guestContact'])
                ->where('status', ReservationStatus::Completed)
                ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
                ->orderByDesc('completed_at')
                ->first()
                ?? 'This table is still being cleaned.';
        }

        if ($table->status === TableStatus::Occupied) {
            return 'This table is currently occupied.';
        }

        $seated = Reservation::query()
            ->with(['user', 'guestContact'])
            ->where('restaurant_id', $restaurant->id)
            ->where('status', ReservationStatus::Seated->value)
            ->when($excludingReservationId, fn (Builder $query) => $query->whereKeyNot($excludingReservationId))
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->first();

        if ($seated !== null) {
            return $seated;
        }

        $conflict = $this->overlappingReservationsQuery(
            restaurant: $restaurant,
            startsAt: $startsAt,
            endsAt: $this->calculateEndTime($restaurant, $startsAt, $partySize),
            excludingReservationId: $excludingReservationId,
        )
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->with(['user', 'guestContact'])
            ->first();

        return $conflict ?? 'This table is not available during the selected shift.';
    }

    /**
     * Names the shift (e.g. "breakfast") covering a given instant, if any —
     * used to make "which reservation is blocking this table" error messages
     * name a shift, not just a raw date/time, so staff can jump straight to
     * the right date+shift on the dashboard.
     */
    public function resolveShiftName(Restaurant $restaurant, CarbonInterface $at): ?string
    {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localAt = Carbon::parse($at)->setTimezone($restaurantTimezone);

        return $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localAt)?->name;
    }

    /**
     * Diagnoses why isTableAvailable() returned false for the same inputs,
     * checking the exact same conditions in the exact same order. Returns a
     * plain-language reason for every case except a genuine reservation-time
     * conflict, where it returns the conflicting Reservation itself so the
     * caller can name the guest and time (callers already have their own
     * guest-name/time-formatting helpers — that's presentation, this method
     * only answers "why").
     */
    public function explainUnavailable(
        Restaurant $restaurant,
        RestaurantTable $table,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): string|Reservation {
        if (! $table->is_active || $table->status === TableStatus::Unavailable) {
            return 'This table has been marked unavailable by staff.';
        }

        if ($table->status === TableStatus::Cleaning) {
            // Name the specific completed reservation that left the table in
            // this state, so staff can find and clear it instead of hunting
            // through the Finished section blind — completeReservation() only
            // ever sets exactly one table to Cleaning per call, so the most
            // recently completed reservation on this table is the one.
            $lastCompleted = Reservation::query()
                ->with(['user', 'guestContact'])
                ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
                ->where('status', ReservationStatus::Completed)
                ->orderByDesc('completed_at')
                ->first();

            return $lastCompleted ?? 'This table is still being cleaned. Mark it Cleared from the Finished section once it\'s ready.';
        }

        if ($table->max_capacity < $partySize || ($partySize > 1 && $table->min_capacity > $partySize)) {
            return sprintf(
                'This table seats %d-%d guests, which doesn\'t fit a party of %d.',
                $table->min_capacity,
                $table->max_capacity,
                $partySize,
            );
        }

        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $localStartsAt = Carbon::parse($startsAt)->setTimezone($restaurantTimezone);
        $shift = $this->restaurantShiftService->resolveShiftForSlot($restaurant, $localStartsAt);

        if ($shift !== null) {
            if (! $this->restaurantShiftService->isPartySizeReleased($shift, $localStartsAt, $partySize)) {
                return 'This party size hasn\'t been released for booking during this shift yet.';
            }

            if ($this->restaurantShiftService->filterTablesByShiftAvailability($shift, collect([$table]), $partySize)->isEmpty()) {
                return 'This table isn\'t available during this shift.';
            }

            if (! $this->restaurantShiftService->isTableReleased($shift, $table, $localStartsAt)) {
                return 'This table hasn\'t been released for booking at this time yet.';
            }
        } elseif ($this->restaurantUsesWeeklyShifts($restaurant)) {
            return 'There\'s no active shift covering this time.';
        }

        $conflict = $this->overlappingReservationsQuery(
            restaurant: $restaurant,
            startsAt: $startsAt,
            endsAt: $this->calculateEndTime($restaurant, $startsAt, $partySize),
            excludingReservationId: $excludingReservationId,
        )
            ->where(fn (Builder $query) => $this->whereReservationUsesTable($query, $table->id))
            ->with(['user', 'guestContact'])
            ->first();

        return $conflict ?? 'This table is not available for this reservation\'s time.';
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

        $rangeStartsAt = collect($windows)->min(fn (array $window): Carbon => $this->windowOpens($window))->copy()->utc();
        $rangeEndsAt = collect($windows)->max(fn (array $window): Carbon => $this->windowCloses($window))->copy()->utc();

        $reservations = $this->overlappingReservationsQuery($restaurant, $rangeStartsAt, $rangeEndsAt)
            ->with('assignedTables:id')
            ->get(['id', 'restaurant_table_id', 'starts_at', 'ends_at', 'party_size']);

        return $this->generateSlots(
            restaurant: $restaurant,
            windows: $windows,
            tables: $tables,
            reservationsByTable: $this->groupReservationsByAssignedTable($reservations),
            allReservations: $reservations,
            partySize: $partySize,
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
            ->with('shifts.availabilityPeriod')
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('date', $date)
            ->get()
            ->groupBy('restaurant_id');

        $shiftsByRestaurant = RestaurantShift::query()
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('is_active', true)
            ->with(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals'])
            ->get()
            ->groupBy('restaurant_id');

        $restaurants = $restaurants->map(function (Restaurant $restaurant) use ($shiftsByRestaurant): Restaurant {
            $restaurant->setRelation(
                'shifts',
                new Collection($shiftsByRestaurant->get($restaurant->id, collect())->all()),
            );

            return $restaurant;
        });

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
            ->min(fn (array $window): Carbon => $this->windowOpens($window))
            ->copy()
            ->utc();
        $rangeEndsAt = $windowsByRestaurant
            ->flatten(1)
            ->max(fn (array $window): Carbon => $this->windowCloses($window))
            ->copy()
            ->utc();
        $reservationsByRestaurant = $this->overlappingReservationsForRestaurantsQuery(
            $bookableRestaurantIds,
            $rangeStartsAt,
            $rangeEndsAt,
        )
            ->with('assignedTables:id')
            ->get(['id', 'restaurant_id', 'restaurant_table_id', 'starts_at', 'ends_at', 'party_size'])
            ->groupBy('restaurant_id');

        foreach ($restaurants as $restaurant) {
            $windows = $windowsByRestaurant->get($restaurant->id, []);
            $tables = $tablesByRestaurant->get($restaurant->id, collect());

            if (empty($windows) || $tables->isEmpty()) {
                continue;
            }

            $restaurantReservations = $reservationsByRestaurant->get($restaurant->id, collect());

            $slotsByRestaurant[$restaurant->id] = $this->generateSlots(
                restaurant: $restaurant,
                windows: $windows,
                tables: $tables,
                reservationsByTable: $this->groupReservationsByAssignedTable($restaurantReservations),
                allReservations: $restaurantReservations,
                partySize: $partySize,
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

        if ($restaurant->shifts()->exists()) {
            $weeklyShift = $restaurant->shifts()
                ->where('restaurant_meal_type_id', $availabilityPeriodId)
                ->where('day_of_week', $localDate->dayOfWeek)
                ->where('is_active', true)
                ->orderBy('starts_at')
                ->first();

            return $weeklyShift
                ? ['starts_at' => $weeklyShift->starts_at, 'ends_at' => $weeklyShift->ends_at]
                : null;
        }

        $schedule = $restaurant->availabilitySchedules()
            ->where('restaurant_meal_type_id', $availabilityPeriodId)
            ->where('day_of_week', $localDate->dayOfWeek)
            ->orderBy('opens_at')
            ->first();

        return $schedule ? ['starts_at' => $schedule->opens_at, 'ends_at' => $schedule->closes_at] : null;
    }

    /**
     * @return list<array{opens: Carbon, closes: Carbon, shift: ?RestaurantShift}>
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
     * @param  list<array{opens: Carbon, closes: Carbon, shift: ?RestaurantShift}>  $windows
     * @param  SupportCollection<int, RestaurantTable>  $tables
     * @param  SupportCollection<int, SupportCollection<int, Reservation>>  $reservationsByTable
     * @param  SupportCollection<int, Reservation>  $allReservations
     * @return list<array<string, mixed>>
     */
    private function generateSlots(
        Restaurant $restaurant,
        array $windows,
        SupportCollection $tables,
        SupportCollection $reservationsByTable,
        SupportCollection $allReservations,
        int $partySize,
        ?string $requesterTimezone = null,
    ): array {
        $restaurantTimezone = $restaurant->timezone ?: config('app.timezone');
        $displayTimezone = $requesterTimezone ?: $restaurantTimezone;
        $fallbackDuration = $restaurant->policy?->reservation_duration_minutes ?? 120;
        $now = Carbon::now();
        $slots = [];

        foreach ($windows as $window) {
            $opensAt = $this->windowOpens($window);
            $closesAt = $this->windowCloses($window);
            /** @var ?RestaurantShift $shift */
            $shift = $window['shift'] ?? null;

            if ($shift !== null) {
                if (! $this->restaurantShiftService->isPartySizeReleased($shift, $opensAt, $partySize)) {
                    continue;
                }

                $tablesForWindow = $this->restaurantShiftService
                    ->filterTablesByShiftAvailability($shift, $tables, $partySize);
            } else {
                $tablesForWindow = $tables;
            }

            if ($tablesForWindow->isEmpty()) {
                continue;
            }

            $duration = $shift !== null
                ? $this->restaurantShiftService->turnDurationForPartySize($shift, $partySize, $fallbackDuration)
                : $fallbackDuration;

            $cursor = $opensAt->copy();

            while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($closesAt)) {
                if ($cursor->lte($now)) {
                    $cursor->addMinutes(15);

                    continue;
                }

                if ($shift !== null) {
                    $intervalMinutes = $shift->flow_interval_minutes;
                    $coversInInterval = $this->coversStartingInInterval(
                        $allReservations,
                        $cursor,
                        $intervalMinutes,
                    );

                    if ($coversInInterval + $partySize > $this->restaurantShiftService->maxCoversForInterval($shift, $cursor)) {
                        $cursor->addMinutes(15);

                        continue;
                    }
                }

                $utcCursor = $cursor->copy()->utc();
                $endsAt = $utcCursor->copy()->addMinutes($duration);
                $table = $tablesForWindow->first(function (RestaurantTable $table) use (
                    $endsAt,
                    $reservationsByTable,
                    $utcCursor,
                    $shift,
                    $cursor,
                ): bool {
                    if ($shift !== null && ! $this->restaurantShiftService->isTableReleased($shift, $table, $cursor)) {
                        return false;
                    }

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
     * @param  SupportCollection<int, Reservation>  $reservations
     */
    private function coversStartingInInterval(
        SupportCollection $reservations,
        CarbonInterface $intervalStart,
        int $intervalMinutes,
    ): int {
        $intervalEnd = $intervalStart->copy()->addMinutes($intervalMinutes);

        return (int) $reservations
            ->filter(fn (Reservation $reservation): bool => Carbon::parse($reservation->starts_at)
                ->setTimezone($intervalStart->getTimezone())
                ->greaterThanOrEqualTo($intervalStart)
                && Carbon::parse($reservation->starts_at)
                    ->setTimezone($intervalStart->getTimezone())
                    ->lessThan($intervalEnd))
            ->sum('party_size');
    }

    private function windowOpens(array $window): Carbon
    {
        return $window['opens'] ?? $window[0];
    }

    private function windowCloses(array $window): Carbon
    {
        return $window['closes'] ?? $window[1];
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
            ->whereNotIn('status', [TableStatus::Unavailable->value, TableStatus::Cleaning->value])
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
            ->where(fn (Builder $query) => $query
                ->whereNotNull('restaurant_table_id')
                ->orWhereHas('assignedTables'))
            ->whereIn('status', [
                ReservationStatus::Booked->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Arrived->value,
                ReservationStatus::PartiallyArrived->value,
                ReservationStatus::LeftMessage->value,
                ReservationStatus::RunningLate->value,
                ReservationStatus::Seated->value,
            ])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    private function whereReservationUsesTable(Builder $query, int $tableId): void
    {
        $query->where('restaurant_table_id', $tableId)
            ->orWhereHas('assignedTables', fn (Builder $tables) => $tables->whereKey($tableId));
    }

    /**
     * @param  SupportCollection<int, Reservation>  $reservations
     * @return SupportCollection<int, int>
     */
    private function assignedTableIds(SupportCollection $reservations): SupportCollection
    {
        return $reservations
            ->flatMap(function (Reservation $reservation): array {
                $ids = $reservation->assignedTables->pluck('id')->all();

                return $ids !== [] ? $ids : array_filter([$reservation->restaurant_table_id]);
            })
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  SupportCollection<int, Reservation>  $reservations
     * @return SupportCollection<int, SupportCollection<int, Reservation>>
     */
    private function groupReservationsByAssignedTable(SupportCollection $reservations): SupportCollection
    {
        $grouped = collect();

        foreach ($reservations as $reservation) {
            $tableIds = $reservation->assignedTables->pluck('id');
            if ($tableIds->isEmpty() && $reservation->restaurant_table_id !== null) {
                $tableIds = collect([$reservation->restaurant_table_id]);
            }

            foreach ($tableIds as $tableId) {
                $grouped->put(
                    (int) $tableId,
                    $grouped->get((int) $tableId, collect())->push($reservation),
                );
            }
        }

        return $grouped;
    }

    /**
     * Build booking windows for the day.
     * Priority: special day → weekly shifts (when any exist) → meal schedules → restaurant_hours.
     *
     * @return list<array{opens: Carbon, closes: Carbon, shift: ?RestaurantShift}>
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
                    'opens' => Carbon::parse($dateStr.' '.$shift->opens_at, $timezone),
                    'closes' => $this->closingTime($dateStr, $shift->opens_at, $shift->closes_at, $timezone),
                    'shift' => null,
                    'source' => 'special_day',
                    'name' => $shift->availabilityPeriod?->name ?? $specialDay->name,
                    'meal_type_id' => $shift->restaurant_meal_type_id,
                ])
                ->values()
                ->all();
        }

        $hasWeeklyShifts = $restaurant->relationLoaded('shifts')
            ? $restaurant->shifts->isNotEmpty()
            : $restaurant->shifts()->exists();

        if ($hasWeeklyShifts) {
            $weeklyShifts = $restaurant->relationLoaded('shifts')
                ? $restaurant->shifts
                    ->where('day_of_week', $dayOfWeek)
                    ->where('is_active', true)
                    ->sortBy('sort_order')
                    ->sortBy('starts_at')
                    ->values()
                : $restaurant->shifts()
                    ->with(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals'])
                    ->where('day_of_week', $dayOfWeek)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->get();

            return $weeklyShifts
                ->map(fn (RestaurantShift $shift) => [
                    'opens' => Carbon::parse($dateStr.' '.$shift->starts_at, $timezone),
                    'closes' => $this->closingTime($dateStr, $shift->starts_at, $shift->ends_at, $timezone),
                    'shift' => $shift,
                    'source' => 'weekly_shift',
                    'name' => $shift->name,
                    'meal_type_id' => $shift->restaurant_meal_type_id,
                ])
                ->all();
        }

        $availabilitySchedules = $restaurant->relationLoaded('availabilitySchedules')
            ? $restaurant->availabilitySchedules->where('day_of_week', $dayOfWeek)->sortBy('opens_at')->values()
            : $restaurant->availabilitySchedules()
                ->with('availabilityPeriod')
                ->where('day_of_week', $dayOfWeek)
                ->orderBy('opens_at')
                ->get();

        if ($availabilitySchedules->isNotEmpty()) {
            return $availabilitySchedules->map(fn ($schedule) => [
                'opens' => Carbon::parse($dateStr.' '.$schedule->opens_at, $timezone),
                'closes' => $this->closingTime($dateStr, $schedule->opens_at, $schedule->closes_at, $timezone),
                'shift' => null,
                'source' => 'meal_schedule',
                'name' => $schedule->availabilityPeriod?->name ?? 'Service',
                'meal_type_id' => $schedule->restaurant_meal_type_id,
            ])->all();
        }

        $hours = $restaurant->hours->firstWhere('day_of_week', $dayOfWeek);

        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return [];
        }

        return [[
            'opens' => Carbon::parse($dateStr.' '.$hours->opens_at, $timezone),
            'closes' => $this->closingTime($dateStr, $hours->opens_at, $hours->closes_at, $timezone),
            'shift' => null,
            'source' => 'restaurant_hours',
            'name' => 'Service',
            'meal_type_id' => null,
        ]];
    }

    private function closingTime(string $date, string $opensAt, string $closesAt, string $timezone): Carbon
    {
        $opens = Carbon::parse($date.' '.$opensAt, $timezone);
        $closes = Carbon::parse($date.' '.$closesAt, $timezone);

        return $closes->lessThanOrEqualTo($opens) ? $closes->addDay() : $closes;
    }

    private function restaurantUsesWeeklyShifts(Restaurant $restaurant): bool
    {
        return $restaurant->relationLoaded('shifts')
            ? $restaurant->shifts->isNotEmpty()
            : $restaurant->shifts()->exists();
    }

    /**
     * Resolve the special day override for the given date, if one exists.
     */
    private function specialDayForDate(Restaurant $restaurant, string $dateStr): ?RestaurantSpecialDay
    {
        if ($restaurant->relationLoaded('specialDays')) {
            $specialDay = $restaurant->specialDays
                ->first(fn (RestaurantSpecialDay $day): bool => $day->date->format('Y-m-d') === $dateStr);

            return $specialDay?->loadMissing('shifts.availabilityPeriod');
        }

        return $restaurant->specialDays()
            ->with('shifts.availabilityPeriod')
            ->where('date', $dateStr)
            ->first();
    }
}
