<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantRewardRule;
use Illuminate\Support\Carbon;

class RestaurantRewardRuleService
{
    public const DEFAULT_POINTS = 100;

    /**
     * Resolve how many loyalty points a reservation starting at the given time should award.
     *
     * Matching rules win by specificity (a rule naming a specific time beats a day-only rule),
     * then by the highest points. When no rule matches, the restaurant's flat default applies.
     */
    public function resolvePoints(Restaurant $restaurant, Carbon $startsAt): int
    {
        $localStart = $startsAt->copy();

        if (filled($restaurant->timezone)) {
            $localStart->setTimezone($restaurant->timezone);
        }

        $day = (int) $localStart->dayOfWeek;
        $time = $localStart->format('H:i');

        $rule = $this->matchingRule($restaurant, $day, $time);

        if ($rule instanceof RestaurantRewardRule) {
            return $rule->points;
        }

        return $restaurant->reservation_reward_points ?? self::DEFAULT_POINTS;
    }

    public function matchingRule(Restaurant $restaurant, int $day, string $time): ?RestaurantRewardRule
    {
        $rules = $restaurant->relationLoaded('rewardRules')
            ? $restaurant->rewardRules
            : $restaurant->rewardRules()->active()->get();

        return $rules
            ->filter(fn (RestaurantRewardRule $rule): bool => $rule->is_active)
            ->filter(function (RestaurantRewardRule $rule) use ($day, $time): bool {
                $days = array_map('intval', $rule->days ?? []);

                if (! in_array($day, $days, true)) {
                    return false;
                }

                $times = $rule->times ?? [];

                return $times === [] || in_array($time, $times, true);
            })
            ->sortBy([
                fn (RestaurantRewardRule $rule): int => empty($rule->times) ? 1 : 0,
                fn (RestaurantRewardRule $rule): int => -$rule->points,
            ])
            ->first();
    }
}
