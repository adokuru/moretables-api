<?php

namespace App\Http\Requests\Concerns;

use App\AveragePriceRange;

trait NormalizesAveragePriceRange
{
    protected function prepareAveragePriceRangeField(string $field = 'average_price_range'): void
    {
        if (! $this->has($field)) {
            return;
        }

        $normalized = AveragePriceRange::normalize($this->input($field));

        if ($normalized !== null) {
            $this->merge([$field => $normalized]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    protected function prepareNestedAveragePriceRangeFields(array $paths): void
    {
        foreach ($paths as $path) {
            if (! $this->has($path)) {
                continue;
            }

            $normalized = AveragePriceRange::normalize($this->input($path));

            if ($normalized !== null) {
                $this->merge([$path => $normalized]);
            }
        }
    }
}
