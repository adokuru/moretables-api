<?php

namespace App\Http\Resources;

use App\Models\RestaurantRewardRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin RestaurantRewardRule */
class RestaurantRewardRuleResource extends JsonResource
{
    private const DAY_LABELS = [
        0 => 'Sundays',
        1 => 'Mondays',
        2 => 'Tuesdays',
        3 => 'Wednesdays',
        4 => 'Thursdays',
        5 => 'Fridays',
        6 => 'Saturdays',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $days = collect($this->days ?? [])->map(fn ($day): int => (int) $day)->values();
        $times = collect($this->times ?? [])->values();

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'points' => $this->points,
            'days' => $days,
            'times' => $times,
            'is_active' => (bool) $this->is_active,
            'applies_all_day' => $times->isEmpty(),
            'day_labels' => $days->map(fn (int $day): string => self::DAY_LABELS[$day] ?? (string) $day)->all(),
            'time_labels' => $times->map(fn (string $time): string => Carbon::createFromFormat('H:i', $time)->format('g:i A'))->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
