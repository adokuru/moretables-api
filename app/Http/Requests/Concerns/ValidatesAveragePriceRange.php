<?php

namespace App\Http\Requests\Concerns;

use App\AveragePriceRange;
use Illuminate\Validation\Rule;

trait ValidatesAveragePriceRange
{
    /**
     * @return array<int, mixed>
     */
    protected function averagePriceRangeRules(bool $required = false): array
    {
        $rules = [Rule::enum(AveragePriceRange::class)];

        array_unshift($rules, $required ? 'required' : 'nullable');
        array_unshift($rules, 'string');

        return $rules;
    }
}
