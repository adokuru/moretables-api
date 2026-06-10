<?php

namespace App\Http\Resources;

use App\Models\RestaurantCancellationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin RestaurantCancellationPolicy */
class RestaurantCancellationPolicyResource extends JsonResource
{
    private const DAY_LABELS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $days = collect($this->days ?? [])->map(fn ($day): int => (int) $day)->values();

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->name,
            'management_method' => $this->management_method?->value,
            'party_size_scope' => $this->party_size_scope?->value,
            'min_party_size' => $this->min_party_size,
            'max_party_size' => $this->max_party_size,
            'hold_charge_amount' => $this->hold_charge_amount,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'days' => $days,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'applies_all_day' => $this->start_time === null && $this->end_time === null,
            'day_labels' => $days->map(fn (int $day): string => self::DAY_LABELS[$day] ?? (string) $day)->all(),
            'time_window_label' => $this->timeWindowLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function timeWindowLabel(): ?string
    {
        if ($this->start_time === null && $this->end_time === null) {
            return null;
        }

        if ($this->start_time !== null && $this->end_time !== null) {
            $start = Carbon::createFromFormat('H:i', $this->start_time)->format('g:i A');
            $end = Carbon::createFromFormat('H:i', $this->end_time)->format('g:i A');

            return "{$start} – {$end}";
        }

        if ($this->start_time !== null) {
            return 'From '.Carbon::createFromFormat('H:i', $this->start_time)->format('g:i A');
        }

        return 'Until '.Carbon::createFromFormat('H:i', $this->end_time)->format('g:i A');
    }
}
