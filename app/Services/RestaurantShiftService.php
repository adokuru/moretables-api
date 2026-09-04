<?php

namespace App\Services;

use App\Enums\RestaurantShiftTurnControlReleasePolicy;
use App\Enums\RestaurantShiftTurnControlRuleType;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\Models\RestaurantTable;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestaurantShiftService
{
    public function seedFromSchedules(Restaurant $restaurant): void
    {
        if ($restaurant->shifts()->exists()) {
            return;
        }

        $restaurant->loadMissing(['availabilitySchedules.availabilityPeriod', 'policy', 'tables', 'tableCombinations']);

        $defaultDuration = $restaurant->policy?->reservation_duration_minutes ?? 120;
        $maxPartySize = $restaurant->policy?->max_party_size ?? 12;
        $defaultMaxCovers = $this->defaultMaxCovers($restaurant);

        foreach ($restaurant->availabilitySchedules as $schedule) {
            $shift = $restaurant->shifts()->create([
                'restaurant_meal_type_id' => $schedule->restaurant_meal_type_id,
                'name' => $schedule->availabilityPeriod?->name ?? 'Shift',
                'day_of_week' => $schedule->day_of_week,
                'starts_at' => $schedule->opens_at,
                'ends_at' => $schedule->closes_at,
                'sort_order' => $schedule->availabilityPeriod?->sort_order ?? 0,
                'flow_default_max_covers' => $defaultMaxCovers,
            ]);

            $this->syncDefaultTurnTimes($shift, $maxPartySize, $defaultDuration);
            $this->syncDefaultTableAvailability($restaurant, $shift);
        }
    }

    /**
     * Fallback cap on covers allowed to *start* within one flow interval
     * (AvailabilityService::generateSlots) when a shift doesn't specify its
     * own — must be at least large enough that a single legitimate party
     * booking this restaurant's own largest table can never be silently
     * rejected on an otherwise-empty day. Prefers the restaurant's declared
     * total_seating_capacity; falls back to summing its actual tables' own
     * max_capacity (total_seating_capacity is a free-text-ish onboarding
     * field, often left unset); falls back to a generous flat number only
     * when neither is available yet (e.g. a shift seeded before any floor
     * plan exists).
     */
    private function defaultMaxCovers(Restaurant $restaurant): int
    {
        if ($restaurant->total_seating_capacity) {
            return max(1, (int) $restaurant->total_seating_capacity);
        }

        $tableCapacity = (int) $restaurant->tables()->sum('max_capacity');

        return $tableCapacity > 0 ? $tableCapacity : 20;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Restaurant $restaurant, array $data): RestaurantShift
    {
        return DB::transaction(function () use ($restaurant, $data): RestaurantShift {
            $shift = $restaurant->shifts()->create($this->shiftAttributes($data, restaurant: $restaurant));

            $this->syncNestedSettings($restaurant, $shift, $data);

            return $shift;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Restaurant $restaurant, RestaurantShift $shift, array $data): RestaurantShift
    {
        return DB::transaction(function () use ($restaurant, $shift, $data): RestaurantShift {
            $shift->update($this->shiftAttributes($data, onlyPresent: true));

            $this->syncNestedSettings($restaurant, $shift, $data, partial: true);

            return $shift->fresh();
        });
    }

    public function delete(RestaurantShift $shift): void
    {
        $shift->delete();
    }

    public function resolveShiftForSlot(Restaurant $restaurant, CarbonInterface $localStartsAt): ?RestaurantShift
    {
        return $restaurant->shifts()
            ->with(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals'])
            ->whereIn('day_of_week', [$localStartsAt->dayOfWeek, $localStartsAt->copy()->subDay()->dayOfWeek])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('starts_at')
            ->get()
            ->first(function (RestaurantShift $shift) use ($localStartsAt): bool {
                $start = $this->shiftStartForSlot($shift, $localStartsAt);
                $end = $start->copy()->setTimeFromTimeString($shift->ends_at);
                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                return $localStartsAt->greaterThanOrEqualTo($start) && $localStartsAt->lessThan($end);
            });
    }

    public function turnDurationForPartySize(RestaurantShift $shift, int $partySize, int $fallbackMinutes): int
    {
        $turnTime = $shift->relationLoaded('turnTimes')
            ? $shift->turnTimes->firstWhere('party_size', $partySize)
            : $shift->turnTimes()->where('party_size', $partySize)->first();

        return $turnTime?->duration_minutes ?? $fallbackMinutes;
    }

    /**
     * @param  Collection<int, RestaurantTable>  $tables
     * @return Collection<int, RestaurantTable>
     */
    public function filterTablesByShiftAvailability(RestaurantShift $shift, $tables, int $partySize): Collection
    {
        $rules = $shift->relationLoaded('tableAvailability')
            ? $shift->tableAvailability
            : $shift->tableAvailability()->get();

        if ($rules->isEmpty()) {
            return $tables;
        }

        return $tables->filter(function (RestaurantTable $table) use ($rules): bool {
            $matchingRules = $rules->filter(function ($rule) use ($table): bool {
                if ($rule->dining_area_id !== null && (int) $rule->dining_area_id !== (int) $table->dining_area_id) {
                    return false;
                }

                if ($rule->table_type !== null && $rule->table_type->value !== $table->table_type) {
                    return false;
                }

                return true;
            });

            if ($matchingRules->isEmpty()) {
                return true;
            }

            return $matchingRules->contains(fn ($rule): bool => (bool) $rule->is_reservable);
        })->values();
    }

    public function isPartySizeReleased(RestaurantShift $shift, CarbonInterface $localNow, ?int $partySize = null): bool
    {
        $partyControls = $shift->turnControls
            ->where('rule_type', RestaurantShiftTurnControlRuleType::PartySize);

        if ($partySize !== null) {
            $partyControls = $partyControls->where('party_size', $partySize);
        }

        if ($partyControls->isEmpty()) {
            return true;
        }

        return $this->isTurnControlInventoryReleased($shift, $localNow);
    }

    public function isTableReleased(RestaurantShift $shift, RestaurantTable $table, CarbonInterface $localNow): bool
    {
        $hasTableRule = $shift->turnControls
            ->where('rule_type', RestaurantShiftTurnControlRuleType::Table)
            ->contains(fn ($rule): bool => (int) $rule->restaurant_table_id === (int) $table->id);

        if (! $hasTableRule) {
            return true;
        }

        return $this->isTurnControlInventoryReleased($shift, $localNow);
    }

    public function maxCoversForInterval(RestaurantShift $shift, CarbonInterface $slotStart): int
    {
        $shiftStart = $this->shiftStartForSlot($shift, $slotStart);
        $intervalStart = function ($interval) use ($shiftStart): Carbon {
            $start = $shiftStart->copy()->setTimeFromTimeString($interval->starts_at);

            return $start->lessThan($shiftStart) ? $start->addDay() : $start;
        };
        $override = $shift->flowIntervals
            ->sortByDesc($intervalStart)
            ->first(fn ($interval): bool => $intervalStart($interval)->lessThanOrEqualTo($slotStart));

        return $override?->max_covers ?? $shift->flow_default_max_covers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shiftAttributes(array $data, bool $onlyPresent = false, ?Restaurant $restaurant = null): array
    {
        $attributes = [];

        foreach ([
            'restaurant_meal_type_id',
            'name',
            'day_of_week',
            'starts_at',
            'ends_at',
            'color',
            'is_active',
            'sort_order',
            'turn_control_release_policy',
            'release_hours_before',
            'flow_interval_minutes',
            'flow_default_max_covers',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (isset($data['flow_controls']) && is_array($data['flow_controls'])) {
            $flow = $data['flow_controls'];

            foreach ([
                'interval_minutes' => 'flow_interval_minutes',
                'default_max_covers' => 'flow_default_max_covers',
                'release_policy' => 'turn_control_release_policy',
                'release_hours_before' => 'release_hours_before',
            ] as $flowKey => $modelKey) {
                if (array_key_exists($flowKey, $flow)) {
                    $attributes[$modelKey] = $flow[$flowKey];
                }
            }
        }

        if ($onlyPresent) {
            return $attributes;
        }

        return array_merge([
            'is_active' => true,
            'turn_control_release_policy' => RestaurantShiftTurnControlReleasePolicy::DontRelease,
            'flow_interval_minutes' => 15,
            'flow_default_max_covers' => $restaurant ? $this->defaultMaxCovers($restaurant) : 20,
            'sort_order' => 0,
        ], $attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncNestedSettings(Restaurant $restaurant, RestaurantShift $shift, array $data, bool $partial = false): void
    {
        if (array_key_exists('turn_times', $data)) {
            $this->syncTurnTimes($shift, $data['turn_times'] ?? []);
        } elseif (! $partial) {
            $defaultDuration = $restaurant->policy?->reservation_duration_minutes ?? 120;
            $maxPartySize = $restaurant->policy?->max_party_size ?? 12;
            $this->syncDefaultTurnTimes($shift, $maxPartySize, $defaultDuration);
        }

        if ($this->hasFloorAssignmentShortcut($data)) {
            $this->syncFloorAssignmentFromShortcut($restaurant, $shift, $data);
        } elseif (array_key_exists('table_availability', $data)) {
            $this->syncTableAvailability($shift, $data['table_availability'] ?? []);
        } elseif (! $partial) {
            $this->syncDefaultTableAvailability($restaurant, $shift);
        }

        if (array_key_exists('turn_controls', $data)) {
            $this->syncTurnControls($restaurant, $shift, $data['turn_controls'] ?? []);
        }

        if (array_key_exists('flow_controls', $data) && is_array($data['flow_controls'])) {
            if (array_key_exists('intervals', $data['flow_controls'])) {
                $this->syncFlowIntervals($shift, $data['flow_controls']['intervals'] ?? []);
            }
        } elseif (array_key_exists('flow_intervals', $data)) {
            $this->syncFlowIntervals($shift, $data['flow_intervals'] ?? []);
        }
    }

    private function syncDefaultTurnTimes(RestaurantShift $shift, int $maxPartySize, int $defaultDuration): void
    {
        $rows = [];

        for ($partySize = 1; $partySize <= $maxPartySize; $partySize++) {
            $rows[] = [
                'party_size' => $partySize,
                'duration_minutes' => $defaultDuration,
            ];
        }

        $this->syncTurnTimes($shift, $rows);
    }

    /**
     * @param  list<array{party_size: int, duration_minutes: int}>  $turnTimes
     */
    private function syncTurnTimes(RestaurantShift $shift, array $turnTimes): void
    {
        $shift->turnTimes()->delete();

        foreach ($turnTimes as $turnTime) {
            $shift->turnTimes()->create([
                'party_size' => $turnTime['party_size'],
                'duration_minutes' => $turnTime['duration_minutes'],
            ]);
        }
    }

    private function syncDefaultTableAvailability(Restaurant $restaurant, RestaurantShift $shift): void
    {
        $tables = $restaurant->tables()->where('is_active', true)->get();

        if ($tables->isEmpty()) {
            $shift->tableAvailability()->create([
                'dining_area_id' => null,
                'table_type' => null,
                'include_combinations' => true,
                'is_reservable' => true,
            ]);

            return;
        }

        $combinationAreaIds = $restaurant->tableCombinations()
            ->pluck('dining_area_id')
            ->filter()
            ->unique();

        $groups = $tables->groupBy(fn (RestaurantTable $table): string => (string) $table->dining_area_id.'|'.$table->table_type);

        $rows = $groups->map(function ($group, string $key) use ($combinationAreaIds): array {
            [$diningAreaId] = explode('|', $key, 2);

            return [
                'dining_area_id' => $diningAreaId !== '' ? (int) $diningAreaId : null,
                'table_type' => $group->first()->table_type,
                'include_combinations' => $combinationAreaIds->contains((int) $diningAreaId),
                'is_reservable' => true,
            ];
        })->values()->all();

        $this->syncTableAvailability($shift, $rows);
    }

    /**
     * @param  list<array{dining_area_id?: int|null, table_type?: string|null, include_combinations?: bool, is_reservable?: bool}>  $rows
     */
    private function hasFloorAssignmentShortcut(array $data): bool
    {
        return array_key_exists('all_floors', $data) || array_key_exists('dining_area_ids', $data);
    }

    private function syncFloorAssignmentFromShortcut(Restaurant $restaurant, RestaurantShift $shift, array $data): void
    {
        if ($data['all_floors'] ?? false) {
            $this->syncTableAvailability($shift, [[
                'dining_area_id' => null,
                'table_type' => null,
                'include_combinations' => true,
                'is_reservable' => true,
            ]]);

            return;
        }

        $diningAreaIds = collect($data['dining_area_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($diningAreaIds === []) {
            return;
        }

        $tables = $restaurant->tables()
            ->where('is_active', true)
            ->whereIn('dining_area_id', $diningAreaIds)
            ->get();

        if ($tables->isEmpty()) {
            $rows = collect($diningAreaIds)
                ->map(fn (int $diningAreaId): array => [
                    'dining_area_id' => $diningAreaId,
                    'table_type' => null,
                    'include_combinations' => true,
                    'is_reservable' => true,
                ])
                ->all();
            $this->syncTableAvailability($shift, $rows);

            return;
        }

        $combinationAreaIds = $restaurant->tableCombinations()
            ->whereIn('dining_area_id', $diningAreaIds)
            ->pluck('dining_area_id')
            ->filter()
            ->unique();

        $groups = $tables->groupBy(
            fn ($table): string => (string) $table->dining_area_id.'|'.$table->table_type
        );

        $rows = $groups
            ->map(function ($group, string $key) use ($combinationAreaIds): array {
                [$diningAreaId] = explode('|', $key, 2);

                return [
                    'dining_area_id' => (int) $diningAreaId,
                    'table_type' => $group->first()->table_type,
                    'include_combinations' => $combinationAreaIds->contains((int) $diningAreaId),
                    'is_reservable' => true,
                ];
            })
            ->values()
            ->all();

        $this->syncTableAvailability($shift, $rows);
    }

    private function syncTableAvailability(RestaurantShift $shift, array $rows): void
    {
        $shift->tableAvailability()->delete();

        foreach ($rows as $row) {
            $shift->tableAvailability()->create([
                'dining_area_id' => $row['dining_area_id'] ?? null,
                'table_type' => $row['table_type'] ?? null,
                'include_combinations' => $row['include_combinations'] ?? true,
                'is_reservable' => $row['is_reservable'] ?? true,
            ]);
        }
    }

    /**
     * @param  list<array{rule_type: string, party_size?: int|null, restaurant_table_id?: int|null, min_turns: int}>  $controls
     */
    private function syncTurnControls(Restaurant $restaurant, RestaurantShift $shift, array $controls): void
    {
        $shift->turnControls()->delete();

        foreach ($controls as $control) {
            $ruleType = RestaurantShiftTurnControlRuleType::from($control['rule_type']);

            if ($ruleType === RestaurantShiftTurnControlRuleType::Table) {
                $tableId = (int) ($control['restaurant_table_id'] ?? 0);
                abort_unless(
                    RestaurantTable::query()
                        ->where('restaurant_id', $restaurant->id)
                        ->whereKey($tableId)
                        ->exists(),
                    422,
                    'Table ID '.$tableId.' does not belong to this restaurant.',
                );
            }

            $shift->turnControls()->create([
                'rule_type' => $ruleType,
                'party_size' => $ruleType === RestaurantShiftTurnControlRuleType::PartySize
                    ? ($control['party_size'] ?? null)
                    : null,
                'restaurant_table_id' => $ruleType === RestaurantShiftTurnControlRuleType::Table
                    ? ($control['restaurant_table_id'] ?? null)
                    : null,
                'min_turns' => $control['min_turns'],
            ]);
        }
    }

    /**
     * @param  list<array{starts_at: string, max_covers: int}>  $intervals
     */
    private function syncFlowIntervals(RestaurantShift $shift, array $intervals): void
    {
        $shift->flowIntervals()->delete();

        foreach ($intervals as $interval) {
            $shift->flowIntervals()->create([
                'starts_at' => $interval['starts_at'],
                'max_covers' => $interval['max_covers'],
            ]);
        }
    }

    private function shiftStartForSlot(RestaurantShift $shift, CarbonInterface $slot): Carbon
    {
        $date = Carbon::parse($slot);
        if ((int) $shift->day_of_week !== $date->dayOfWeek) {
            $date->subDay();
        }

        return $date->setTimeFromTimeString($shift->starts_at);
    }

    private function isTurnControlInventoryReleased(RestaurantShift $shift, CarbonInterface $localNow): bool
    {
        $policy = $shift->turn_control_release_policy;

        if ($policy === RestaurantShiftTurnControlReleasePolicy::DontRelease) {
            return false;
        }

        $shiftStart = $this->shiftStartForSlot($shift, $localNow);

        if ($policy === RestaurantShiftTurnControlReleasePolicy::AtShiftStart) {
            return $localNow->greaterThanOrEqualTo($shiftStart);
        }

        $hoursBefore = $shift->release_hours_before ?? 0;

        return $localNow->greaterThanOrEqualTo($shiftStart->copy()->subHours($hoursBefore));
    }
}
