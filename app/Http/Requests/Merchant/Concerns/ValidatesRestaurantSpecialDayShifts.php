<?php

namespace App\Http\Requests\Merchant\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesRestaurantSpecialDayShifts
{
    protected function validateNonOverlappingShifts(Validator $validator): void
    {
        $shifts = collect($this->input('shifts', []))
            ->filter(fn (mixed $shift): bool => is_array($shift)
                && isset($shift['opens_at'], $shift['closes_at'])
                && is_string($shift['opens_at'])
                && is_string($shift['closes_at'])
                && $shift['opens_at'] < $shift['closes_at'])
            ->sortBy('opens_at')
            ->values();

        $previousClosesAt = null;

        foreach ($shifts as $shift) {
            if ($previousClosesAt !== null && $shift['opens_at'] < $previousClosesAt) {
                $validator->errors()->add('shifts', 'Special day shifts may not overlap.');

                return;
            }

            $previousClosesAt = $shift['closes_at'];
        }
    }
}
