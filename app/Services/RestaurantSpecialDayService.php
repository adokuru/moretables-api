<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSpecialDay;
use Illuminate\Support\Facades\DB;

class RestaurantSpecialDayService
{
    /**
     * @param  array{name: string, date: string, is_closed?: bool, shifts?: list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>}  $data
     */
    public function create(Restaurant $restaurant, array $data): RestaurantSpecialDay
    {
        return DB::transaction(function () use ($restaurant, $data): RestaurantSpecialDay {
            $specialDay = $restaurant->specialDays()->create([
                'name' => $data['name'],
                'date' => $data['date'],
                'is_closed' => $data['is_closed'] ?? false,
            ]);

            $this->syncShifts($restaurant, $specialDay, $data['is_closed'] ?? false, $data['shifts'] ?? []);

            return $specialDay;
        });
    }

    /**
     * @param  array{name?: string, date?: string, is_closed?: bool, shifts?: list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>}  $data
     */
    public function update(Restaurant $restaurant, RestaurantSpecialDay $specialDay, array $data): RestaurantSpecialDay
    {
        return DB::transaction(function () use ($restaurant, $specialDay, $data): RestaurantSpecialDay {
            $specialDay->fill(array_filter([
                'name' => $data['name'] ?? null,
                'date' => $data['date'] ?? null,
            ], fn ($value): bool => $value !== null));

            if (array_key_exists('is_closed', $data)) {
                $specialDay->is_closed = $data['is_closed'];
            }

            $specialDay->save();

            if (array_key_exists('shifts', $data) || array_key_exists('is_closed', $data)) {
                $this->syncShifts($restaurant, $specialDay, $specialDay->is_closed, $data['shifts'] ?? []);
            }

            return $specialDay;
        });
    }

    /**
     * @param  list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>  $shifts
     */
    private function syncShifts(Restaurant $restaurant, RestaurantSpecialDay $specialDay, bool $isClosed, array $shifts): void
    {
        $specialDay->shifts()->delete();

        if ($isClosed) {
            return;
        }

        $availabilityPeriodIds = $restaurant->availabilityPeriods()->pluck('id')->all();

        foreach ($shifts as $shift) {
            abort_unless(
                in_array((int) $shift['restaurant_meal_type_id'], $availabilityPeriodIds, true),
                422,
                'Availability period ID '.$shift['restaurant_meal_type_id'].' does not belong to this restaurant.',
            );

            $specialDay->shifts()->create([
                'restaurant_meal_type_id' => $shift['restaurant_meal_type_id'],
                'opens_at' => $shift['opens_at'],
                'closes_at' => $shift['closes_at'],
            ]);
        }
    }
}
