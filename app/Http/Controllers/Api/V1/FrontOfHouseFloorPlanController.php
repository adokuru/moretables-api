<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiningAreaResource;
use App\Http\Resources\RestaurantTableResource;
use App\Models\DiningArea;
use App\Models\Restaurant;
use App\ReservationStatus;
use App\Services\AvailabilityService;
use App\Services\RestaurantDateRangeService;
use App\TableStatus;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Floor plan endpoints let front-of-house staff switch between dining areas
 * and see every table's live occupancy status for a given service period.
 */
#[Group('Front of House / Floor Plan', weight: 36)]
class FrontOfHouseFloorPlanController extends Controller
{
    public function __construct(
        private readonly RestaurantDateRangeService $dateRanges,
        private readonly AvailabilityService $availabilityService,
    ) {}

    private function resolveDate(Request $request, Restaurant $restaurant): Carbon
    {
        $timezone = $restaurant->timezone ?? 'UTC';

        return $request->filled('date')
            ? Carbon::parse($request->string('date')->toString(), $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();
    }

    private function validateCommonParams(Request $request): void
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at', 'after:starts_at'],
            'meal_type_id' => ['nullable', 'integer', 'exists:restaurant_meal_types,id'],
        ]);
    }

    private function resolveWindow(Request $request, Restaurant $restaurant, Carbon $date): array
    {
        $windowStart = null;
        $windowEnd = null;
        $availabilityPeriod = null;

        if ($request->filled('meal_type_id')) {
            $availabilityPeriod = $restaurant->availabilityPeriods()
                ->findOrFail($request->integer('meal_type_id'));

            $window = $this->availabilityService->timeWindowForMealType(
                $restaurant,
                $date->toDateString(),
                $availabilityPeriod->id,
            );
            $windowStart = $window['starts_at'] ?? null;
            $windowEnd = $window['ends_at'] ?? null;
        } elseif ($request->filled('starts_at') && $request->filled('ends_at')) {
            $windowStart = $request->string('starts_at')->toString();
            $windowEnd = $request->string('ends_at')->toString();
        }

        return compact('windowStart', 'windowEnd', 'availabilityPeriod');
    }

    /**
     * List all floors (dining areas).
     *
     * Returns all active dining areas for the restaurant, ordered by `sort_order`.
     * Use this to populate the floor switcher dropdown in the front-of-house UI.
     * Tables are not included here — fetch them via the individual floor endpoint.
     */
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $floors = $restaurant->diningAreas()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => DiningAreaResource::collection($floors),
        ]);
    }

    /**
     * Get a floor with live table status.
     *
     * Returns the dining area and all its tables. Each table is enriched with a
     * `live_status` field and a `current_reservation` summary reflecting the actual
     * occupancy for the requested date and service window:
     *
     * | `live_status`  | Meaning                                              |
     * |----------------|------------------------------------------------------|
     * | `occupied`     | A guest is currently seated at this table            |
     * | `reserved`     | An upcoming booking (booked / confirmed / arrived)   |
     * | `available`    | No active reservation for this period                |
     * | `unavailable`  | Table is marked unavailable by staff                 |
     *
     * ### Time filtering
     * Pass `starts_at` + `ends_at` (`HH:MM`) to scope the reservation lookup to a
     * specific service window, or pass `meal_type_id` to use a saved meal schedule.
     */
    public function show(Request $request, Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'availabilityPeriod' => $availabilityPeriod] = $this->resolveWindow($request, $restaurant, $date);

        // Load tables for this floor
        $tables = $diningArea->tables()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Fetch all active reservations for these tables on the requested date in one query
        $tableIds = $tables->pluck('id');
        $dateRange = $windowStart && $windowEnd
            ? $this->dateRanges->forTimeWindow($restaurant, $date->toDateString(), $windowStart, $windowEnd)
            : $this->dateRanges->forDate($restaurant, $date->toDateString());

        $reservationQuery = $restaurant->reservations()
            ->with(['user', 'guestContact'])
            ->whereIn('restaurant_table_id', $tableIds)
            ->where('starts_at', '>=', $dateRange['start'])
            ->where('starts_at', '<', $dateRange['end'])
            ->whereIn('status', [
                ReservationStatus::Booked,
                ReservationStatus::Confirmed,
                ReservationStatus::Arrived,
                ReservationStatus::Seated,
            ]);

        // Key reservations by table id — one active reservation per table
        $reservationsByTable = $reservationQuery
            ->get()
            ->keyBy('restaurant_table_id');

        // Build enriched table list
        $enrichedTables = $tables->map(function ($table) use ($reservationsByTable) {
            $reservation = $reservationsByTable->get($table->id);

            // Derive live status
            if ($table->status === TableStatus::Unavailable) {
                $liveStatus = 'unavailable';
            } elseif (! $reservation) {
                $liveStatus = 'available';
            } elseif ($reservation->status === ReservationStatus::Seated) {
                $liveStatus = 'occupied';
            } else {
                $liveStatus = 'reserved';
            }

            $tableData = RestaurantTableResource::make($table)->resolve();
            $tableData['live_status'] = $liveStatus;
            $tableData['current_reservation'] = $reservation ? [
                'id' => $reservation->id,
                'reference' => $reservation->reservation_reference,
                'status' => $reservation->status?->value,
                'party_size' => $reservation->party_size,
                'starts_at' => $reservation->starts_at?->toIso8601String(),
                'ends_at' => $reservation->ends_at?->toIso8601String(),
                'seated_at' => $reservation->seated_at?->toIso8601String(),
                'guest_name' => $reservation->user
                    ? trim($reservation->user->first_name.' '.$reservation->user->last_name)
                    : trim(($reservation->guestContact?->first_name ?? '').' '.($reservation->guestContact?->last_name ?? '')),
            ] : null;

            return $tableData;
        });

        return response()->json([
            'date' => $date->toDateString(),
            'period' => [
                'meal_type' => $availabilityPeriod ? ['id' => $availabilityPeriod->id, 'name' => $availabilityPeriod->name] : null,
                'starts_at' => $windowStart,
                'ends_at' => $windowEnd,
            ],
            'floor' => [
                'id' => $diningArea->id,
                'name' => $diningArea->name,
                'category' => $diningArea->category,
                'floor_type' => $diningArea->floor_type,
                'description' => $diningArea->description,
                'tags' => $diningArea->tags ?? [],
            ],
            'tables' => $enrichedTables->values(),
        ]);
    }
}
