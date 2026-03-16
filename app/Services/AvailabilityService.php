<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\ReservationStatus;
use App\TableStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

        $blockedTableIds = Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNotNull('restaurant_table_id')
            ->whereIn('status', [
                ReservationStatus::Booked->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Arrived->value,
                ReservationStatus::Seated->value,
            ])
            ->when($excludingReservationId, fn ($query) => $query->whereKeyNot($excludingReservationId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->pluck('restaurant_table_id');

        return RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->where('min_capacity', '<=', $partySize)
            ->where('max_capacity', '>=', $partySize)
            ->where('status', '!=', TableStatus::Unavailable->value)
            ->whereNotIn('id', $blockedTableIds)
            ->orderBy('max_capacity')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAvailableSlots(Restaurant $restaurant, string $date, int $partySize): array
    {
        $timezone = $restaurant->timezone ?: config('app.timezone');
        $localDate = Carbon::createFromFormat('Y-m-d', $date, $timezone);
        $hours = $restaurant->hours->firstWhere('day_of_week', (int) $localDate->dayOfWeek);

        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return [];
        }

        $opensAt = Carbon::parse($localDate->format('Y-m-d').' '.$hours->opens_at, $timezone);
        $closesAt = Carbon::parse($localDate->format('Y-m-d').' '.$hours->closes_at, $timezone);
        $duration = $restaurant->policy?->reservation_duration_minutes ?? 120;

        $slots = [];
        $cursor = $opensAt->copy();

        while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($closesAt)) {
            $utcCursor = $cursor->copy()->utc();
            $table = $this->findAvailableTable($restaurant, $utcCursor, $partySize);

            if ($table) {
                $slots[] = [
                    'starts_at' => $utcCursor->toIso8601String(),
                    'ends_at' => $utcCursor->copy()->addMinutes($duration)->toIso8601String(),
                    'local_starts_at' => $cursor->toIso8601String(),
                    'local_ends_at' => $cursor->copy()->addMinutes($duration)->toIso8601String(),
                    'table_id' => $table->id,
                ];
            }

            $cursor->addMinutes(30);
        }

        return $slots;
    }
}
