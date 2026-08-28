<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\RestaurantTableResource;
use App\Models\Restaurant;
use App\ReservationStatus;
use App\Services\AvailabilityService;
use App\Services\RestaurantDateRangeService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Front of House / Operations', weight: 35)]
class FrontOfHouseOperationsController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly RestaurantDateRangeService $dateRanges,
    ) {}

    /**
     * List effective service periods in chronological order (maximum 31 days).
     */
    public function servicePeriods(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $timezone = $restaurant->timezone ?: config('app.timezone');
        $from = Carbon::parse($validated['from'], $timezone)->startOfDay();
        $to = Carbon::parse($validated['to'], $timezone)->startOfDay();

        abort_if($from->diffInDays($to) > 30, 422, 'The service-period range may not exceed 31 days.');

        $restaurant->load([
            'hours',
            'shifts.mealType',
            'shifts.turnTimes',
            'policy',
            'specialDays.shifts.availabilityPeriod',
            'availabilitySchedules.availabilityPeriod',
        ]);

        $periods = collect(CarbonPeriod::create($from, $to))
            ->flatMap(function ($date) use ($restaurant): array {
                return collect($this->availabilityService->effectiveTimeWindows($restaurant, $date->format('Y-m-d')))
                    ->map(function (array $window) use ($date, $restaurant): array {
                        $shift = $window['shift'] ?? null;
                        $startsAt = $window['opens'];
                        $endsAt = $window['closes'];
                        $source = $window['source'] ?? ($shift ? 'weekly_shift' : 'restaurant_hours');

                        return [
                            'key' => implode(':', [
                                $date->format('Y-m-d'),
                                $source,
                                $shift?->id ?? $window['meal_type_id'] ?? 'service',
                                $startsAt->format('Hi'),
                            ]),
                            'date' => $date->format('Y-m-d'),
                            'name' => $window['name'] ?? $shift?->name ?? $shift?->mealType?->name ?? 'Service',
                            'starts_at' => $startsAt->format('H:i'),
                            'ends_at' => $endsAt->format('H:i'),
                            'starts_at_iso' => $startsAt->toIso8601String(),
                            'ends_at_iso' => $endsAt->toIso8601String(),
                            'shift_id' => $shift?->id,
                            'meal_type_id' => $window['meal_type_id'] ?? $shift?->restaurant_meal_type_id,
                            'color' => $shift?->color,
                            'default_turn_time_minutes' => $restaurant->policy?->reservation_duration_minutes ?? 120,
                            'turn_times' => $shift?->turnTimes?->map(fn ($turnTime): array => [
                                'party_size' => $turnTime->party_size,
                                'duration_minutes' => $turnTime->duration_minutes,
                            ])->values()->all() ?? [],
                            'source' => $source,
                        ];
                    })
                    ->all();
            })
            ->sortBy('starts_at_iso')
            ->values();

        return response()->json(['timezone' => $timezone, 'data' => $periods]);
    }

    /**
     * Find tables available for a party at the requested timestamp.
     */
    public function availableTables(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'party_size' => ['required', 'integer', 'min:1'],
            'dining_area_id' => ['nullable', 'integer'],
            'excluding_reservation_id' => [
                'nullable',
                'integer',
                Rule::exists('reservations', 'id')->where('restaurant_id', $restaurant->id),
            ],
        ]);

        $tables = $this->availabilityService->availableTables(
            $restaurant,
            Carbon::parse($validated['starts_at']),
            (int) $validated['party_size'],
            isset($validated['excluding_reservation_id']) ? (int) $validated['excluding_reservation_id'] : null,
            isset($validated['dining_area_id']) ? (int) $validated['dining_area_id'] : null,
        );

        $combinationCandidates = $this->availabilityService->combinationCandidateTables(
            $restaurant,
            Carbon::parse($validated['starts_at']),
            (int) $validated['party_size'],
            isset($validated['excluding_reservation_id']) ? (int) $validated['excluding_reservation_id'] : null,
        );
        $candidateIds = $combinationCandidates->modelKeys();
        $candidateTables = $combinationCandidates->keyBy('id');
        $availableCombinations = $restaurant->tableCombinations()
            ->where('min_capacity', '<=', (int) $validated['party_size'])
            ->where('max_capacity', '>=', (int) $validated['party_size'])
            ->orderBy('id')
            ->get()
            ->filter(fn ($combination): bool => collect($combination->table_ids)->every(
                fn ($tableId): bool => in_array((int) $tableId, $candidateIds, true),
            ))
            ->map(fn ($combination): array => [
                'id' => $combination->id,
                'dining_area_id' => $combination->dining_area_id,
                'table_ids' => $combination->table_ids,
                'min_capacity' => $combination->min_capacity,
                'max_capacity' => $combination->max_capacity,
                'tables' => RestaurantTableResource::collection(
                    collect($combination->table_ids)->map(fn ($tableId) => $candidateTables->get((int) $tableId)),
                ),
            ])
            ->values();

        return response()->json([
            'data' => RestaurantTableResource::collection($tables),
            'combination_candidates' => RestaurantTableResource::collection($combinationCandidates),
            'available_combinations' => $availableCombinations,
        ]);
    }

    /**
     * List cancelled and no-show reservations for a service window.
     */
    public function removed(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at'],
        ]);
        $range = isset($validated['starts_at'], $validated['ends_at'])
            ? $this->dateRanges->forTimeWindow($restaurant, $validated['date'], $validated['starts_at'], $validated['ends_at'])
            : $this->dateRanges->forDate($restaurant, $validated['date']);

        $reservations = $restaurant->reservations()
            ->with(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests'])
            ->whereIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow])
            ->whereBetween('starts_at', [$range['start'], $range['end']])
            ->orderBy('starts_at')
            ->paginate(20);

        return ReservationResource::collection($reservations)->response();
    }
}
