<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use Illuminate\Support\Carbon;

class RestaurantCancellationPolicyService
{
    public function matchingPolicy(Restaurant $restaurant, Carbon $at, int $partySize): ?RestaurantCancellationPolicy
    {
        $localAt = $at->copy();

        if (filled($restaurant->timezone)) {
            $localAt->setTimezone($restaurant->timezone);
        }

        $day = (int) $localAt->dayOfWeek;
        $time = $localAt->format('H:i');
        $date = $localAt->toDateString();
        $largePartyThreshold = $restaurant->policy?->large_party_threshold;

        $policies = $restaurant->relationLoaded('cancellationPolicies')
            ? $restaurant->cancellationPolicies
            : $restaurant->cancellationPolicies()->active()->get();

        return $policies
            ->filter(fn (RestaurantCancellationPolicy $policy): bool => $policy->is_active)
            ->filter(fn (RestaurantCancellationPolicy $policy): bool => $policy->appliesToPartySize($partySize, $largePartyThreshold))
            ->filter(function (RestaurantCancellationPolicy $policy) use ($day, $time, $date): bool {
                $days = array_map('intval', $policy->days ?? []);

                if (! in_array($day, $days, true)) {
                    return false;
                }

                if ($policy->starts_on !== null && $date < $policy->starts_on->toDateString()) {
                    return false;
                }

                if ($policy->ends_on !== null && $date > $policy->ends_on->toDateString()) {
                    return false;
                }

                if ($policy->start_time !== null && $time < $policy->start_time) {
                    return false;
                }

                if ($policy->end_time !== null && $time > $policy->end_time) {
                    return false;
                }

                return true;
            })
            ->sortBy([
                fn (RestaurantCancellationPolicy $policy): int => $policy->start_time === null && $policy->end_time === null ? 1 : 0,
                fn (RestaurantCancellationPolicy $policy): int => -$policy->sort_order,
                fn (RestaurantCancellationPolicy $policy): int => -$policy->id,
            ])
            ->first();
    }
}
