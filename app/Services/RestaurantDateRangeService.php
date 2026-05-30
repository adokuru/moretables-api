<?php

namespace App\Services;

use App\Models\Restaurant;
use Carbon\CarbonImmutable;

class RestaurantDateRangeService
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function forDate(Restaurant $restaurant, string $date): array
    {
        $start = CarbonImmutable::parse($date, $this->timezone($restaurant))->startOfDay();

        return [
            'start' => $start->utc(),
            'end' => $start->addDay()->utc(),
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function forTimeWindow(Restaurant $restaurant, string $date, string $startsAt, string $endsAt): array
    {
        $timezone = $this->timezone($restaurant);

        return [
            'start' => CarbonImmutable::parse("{$date} {$startsAt}", $timezone)->utc(),
            'end' => CarbonImmutable::parse("{$date} {$endsAt}", $timezone)->utc(),
        ];
    }

    private function timezone(Restaurant $restaurant): string
    {
        return $restaurant->timezone ?: (string) config('app.timezone');
    }
}
