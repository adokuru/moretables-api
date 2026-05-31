<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSpecialDay;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantSpecialDayService
{
    /**
     * @param  array{name: string, date: string, is_closed?: bool, shifts?: list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>}  $data
     */
    public function create(Restaurant $restaurant, array $data): RestaurantSpecialDay
    {
        try {
            return DB::transaction(function () use ($restaurant, $data): RestaurantSpecialDay {
                $specialDay = $restaurant->specialDays()->create([
                    'name' => $data['name'],
                    'date' => $data['date'],
                    'is_closed' => $data['is_closed'] ?? false,
                ]);

                $this->syncShifts($restaurant, $specialDay, $data['is_closed'] ?? false, $data['shifts'] ?? []);

                return $specialDay;
            });
        } catch (QueryException $exception) {
            $this->throwDuplicateDateValidationException($exception);
        }
    }

    /**
     * @param  array{name?: string, date?: string, is_closed?: bool, shifts?: list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>}  $data
     */
    public function update(Restaurant $restaurant, RestaurantSpecialDay $specialDay, array $data): RestaurantSpecialDay
    {
        try {
            return DB::transaction(function () use ($restaurant, $specialDay, $data): RestaurantSpecialDay {
                $specialDay->fill(array_filter([
                    'name' => $data['name'] ?? null,
                    'date' => $data['date'] ?? null,
                ], fn ($value): bool => $value !== null));

                if (array_key_exists('is_closed', $data)) {
                    $specialDay->is_closed = $data['is_closed'];
                }

                $specialDay->save();

                if (array_key_exists('shifts', $data) || (array_key_exists('is_closed', $data) && $specialDay->is_closed)) {
                    $this->syncShifts($restaurant, $specialDay, $specialDay->is_closed, $data['shifts'] ?? []);
                }

                return $specialDay;
            });
        } catch (QueryException $exception) {
            $this->throwDuplicateDateValidationException($exception);
        }
    }

    /**
     * @param  list<array{restaurant_meal_type_id: int, opens_at: string, closes_at: string}>  $shifts
     */
    private function syncShifts(Restaurant $restaurant, RestaurantSpecialDay $specialDay, bool $isClosed, array $shifts): void
    {
        if (! $isClosed && $shifts === []) {
            throw ValidationException::withMessages([
                'shifts' => ['At least one shift is required when the special day is open.'],
            ]);
        }

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

    private function throwDuplicateDateValidationException(QueryException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        if (in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'restaurant_special_days')) {
            throw ValidationException::withMessages([
                'date' => ['A special day already exists for this date.'],
            ]);
        }

        throw $exception;
    }
}
