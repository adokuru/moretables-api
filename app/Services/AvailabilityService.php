<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\ReservationStatus;
use App\TableStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AvailabilityService
{
    public function calculateEndTime(Restaurant $restaurant, CarbonInterface $startsAt): Carbon
    {
        return Carbon::parse($startsAt)->addMinutes($restaurant->policy?->reservation_duration_minutes ?? 120);
    }

    public function findAvailableTable(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): ?RestaurantTable {
        return $this->availableTables($restaurant, $startsAt, $partySize, $excludingReservationId)->first();
    }

    public function availableTables(
        Restaurant $restaurant,
        CarbonInterface $startsAt,
        int $partySize,
        ?int $excludingReservationId = null,
    ): Collection {
        $endsAt = $this->calculateEndTime($restaurant, $startsAt);

        $blockedTableIds = $this->overlappingReservationsQuery($restaurant, $startsAt, $endsAt, $excludingReservationId)
            ->pluck('restaurant_table_id');

        return $this->eligibleTablesQuery($restaurant, $partySize)
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
        $restaurantTz = $restaurant->timezone ?: config('app.timezone');
        $displayTz = $requesterTimezone ?: $restaurantTz;

        $localDate = Carbon::createFromFormat('Y-m-d', $date, $restaurantTz);
        $dayOfWeek = (int) $localDate->dayOfWeek;
        $dateStr = $localDate->format('Y-m-d');
        $duration = $restaurant->policy?->reservation_duration_minutes ?? 120;
        $now = Carbon::now();

        $windows = $this->buildTimeWindows($restaurant, $dayOfWeek, $dateStr, $restaurantTz);

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
                    $localCursor = $utcCursor->copy()->setTimezone($displayTz);
                    $slots[] = [
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

        return $slots;
    }

    /**
     * @return Builder<RestaurantTable>
     */
    private function eligibleTablesQuery(Restaurant $restaurant, int $partySize): Builder
    {
        return RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
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
        return Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNotNull('restaurant_table_id')
            ->whereIn('status', [
                ReservationStatus::Booked->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Arrived->value,
                ReservationStatus::Seated->value,
            ])
            ->when($excludingReservationId, fn (Builder $query) => $query->whereKeyNot($excludingReservationId))
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
        $mealSchedules = $restaurant->relationLoaded('mealSchedules')
            ? $restaurant->mealSchedules->where('day_of_week', $dayOfWeek)->sortBy('opens_at')->values()
            : collect();

        if ($mealSchedules->isNotEmpty()) {
            return $mealSchedules->map(fn ($s) => [
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
}
