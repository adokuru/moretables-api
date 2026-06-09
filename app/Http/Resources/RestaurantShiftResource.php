<?php

namespace App\Http\Resources;

use App\Models\RestaurantShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantShift
 */
class RestaurantShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'restaurant_meal_type_id' => $this->restaurant_meal_type_id,
            'name' => $this->name,
            'day_of_week' => $this->day_of_week,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'turn_times' => $this->whenLoaded('turnTimes', fn () => $this->turnTimes->map(fn ($turnTime): array => [
                'party_size' => $turnTime->party_size,
                'duration_minutes' => $turnTime->duration_minutes,
            ])->values()),
            ...$this->floorAssignmentFields(),
            'table_availability' => $this->whenLoaded('tableAvailability', fn () => $this->tableAvailability->map(fn ($row): array => [
                'id' => $row->id,
                'dining_area_id' => $row->dining_area_id,
                'table_type' => $row->table_type?->value,
                'include_combinations' => $row->include_combinations,
                'is_reservable' => $row->is_reservable,
            ])->values()),
            'turn_controls' => $this->whenLoaded('turnControls', fn () => $this->turnControls->map(fn ($control): array => [
                'id' => $control->id,
                'rule_type' => $control->rule_type->value,
                'party_size' => $control->party_size,
                'restaurant_table_id' => $control->restaurant_table_id,
                'min_turns' => $control->min_turns,
            ])->values()),
            'flow_controls' => [
                'interval_minutes' => $this->flow_interval_minutes,
                'default_max_covers' => $this->flow_default_max_covers,
                'release_policy' => $this->turn_control_release_policy->value,
                'release_hours_before' => $this->release_hours_before,
                'intervals' => $this->whenLoaded('flowIntervals', fn () => $this->flowIntervals->map(fn ($interval): array => [
                    'id' => $interval->id,
                    'starts_at' => $interval->starts_at,
                    'max_covers' => $interval->max_covers,
                ])->values(), []),
            ],
        ];
    }

    /**
     * @return array{all_floors?: bool, dining_area_ids?: array<int, int>}
     */
    private function floorAssignmentFields(): array
    {
        if (! $this->relationLoaded('tableAvailability')) {
            return [];
        }

        $rules = $this->tableAvailability;

        $hasCatchAll = $rules->contains(
            fn ($rule): bool => $rule->dining_area_id === null && $rule->table_type === null
        );

        if ($hasCatchAll || $rules->isEmpty()) {
            return [
                'all_floors' => true,
                'dining_area_ids' => [],
            ];
        }

        $diningAreaIds = $rules
            ->pluck('dining_area_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [
            'all_floors' => false,
            'dining_area_ids' => $diningAreaIds,
        ];
    }
}
