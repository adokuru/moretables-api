<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\AssignReservationTableRequest;
use App\Http\Requests\Merchant\SeatReservationRequest;
use App\Http\Requests\Merchant\StoreMerchantReservationRequest;
use App\Http\Requests\Merchant\UpdateMerchantReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\ReservationServiceStage;
use App\Services\ReservationService;
use App\Services\RestaurantDateRangeService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Merchant Reservations', weight: 36)]
class MerchantReservationController extends Controller
{
    public function __construct(
        protected ReservationService $reservationService,
        protected RestaurantDateRangeService $dateRanges,
    ) {}

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $reservationsQuery = $restaurant->reservations()
            ->with(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('starts_at');

        if ($request->filled('date')) {
            $range = $this->dateRanges->forDate($restaurant, $request->string('date')->toString());
            $reservationsQuery
                ->where('starts_at', '>=', $range['start'])
                ->where('starts_at', '<', $range['end']);
        }

        $reservations = $reservationsQuery->paginate(20);

        return response()->json(ReservationResource::collection($reservations));
    }

    /**
     * Create a reservation. For overnight service, send starts_at with the actual calendar date and timezone offset; the previous day’s service window and shift rules remain applicable after midnight. Closed special days and reservation duration limits still apply. A 422 is returned when the requested time is outside effective booking hours or availability changes while processing.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function store(StoreMerchantReservationRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        $restaurant->loadMissing('policy');

        $reservation = $this->reservationService->createMerchantReservation($request->user(), $restaurant, $request->validated());

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => ReservationResource::make($reservation),
        ], 201);
    }

    public function show(Restaurant $restaurant, Reservation $reservation): ReservationResource
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        return ReservationResource::make($reservation->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']));
    }

    public function update(UpdateMerchantReservationRequest $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->updateReservation($reservation, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Reservation updated successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function move(Request $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $validated = $request->validate([
            'starts_at' => ['nullable', 'date', 'required_without:restaurant_table_id'],
            'restaurant_table_id' => ['nullable', 'integer', 'required_without:starts_at'],
        ]);

        $table = isset($validated['restaurant_table_id'])
            ? RestaurantTable::query()->where('restaurant_id', $restaurant->id)->findOrFail($validated['restaurant_table_id'])
            : null;

        $updatedReservation = $this->reservationService->moveReservation(
            $reservation,
            $request->user(),
            $validated['starts_at'] ?? null,
            $table,
        );

        return response()->json([
            'message' => 'Reservation moved successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Assign the requested table after rechecking it inside the reservation lock. A retryable 422 is returned if it is unavailable.
     * Saved combinations use their configured capacity range, even when it exceeds the sum of their member tables' capacities.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function assignTable(AssignReservationTableRequest $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $tables = $this->reservationService->resolveTableSelection(
            $restaurant,
            $request->validated(),
            $reservation->party_size,
        );
        $updatedReservation = $this->reservationService->assignTables($reservation, $tables, $request->user());

        return response()->json([
            'message' => 'Reservation table assigned successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Seat a reservation at its assigned table.
     * The configured capacity of an exact saved combination is honoured when seating its assigned tables.
     *
     * Returns a retryable 422 on `restaurant_table_id` when another party is
     * seated there or the table now conflicts with another active reservation.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function seat(SeatReservationRequest $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $validated = $request->validated();
        $hasSelection = isset($validated['restaurant_table_id'])
            || isset($validated['table_combination_id'])
            || isset($validated['restaurant_table_ids']);
        $tables = $hasSelection
            ? $this->reservationService->resolveTableSelection($restaurant, $validated, $reservation->party_size)
            : null;

        $updatedReservation = $this->reservationService->seatReservation(
            $reservation,
            $request->user(),
            $tables,
            isset($validated['service_stage']) ? ReservationServiceStage::from($validated['service_stage']) : null,
        );

        return response()->json([
            'message' => 'Reservation seated successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function clearTables(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->clearReservationTables($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation tables cleared successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function complete(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->completeReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation completed successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Correct the dining progression while the party remains seated.
     */
    public function updateServiceStage(Request $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $validated = $request->validate([
            'service_stage' => ['required', Rule::enum(ReservationServiceStage::class)],
        ]);

        $updatedReservation = $this->reservationService->updateServiceStage(
            $reservation,
            ReservationServiceStage::from($validated['service_stage']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Service stage updated successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Cancel a reservation.
     *
     * Releases reserved preassigned tables, or all assigned tables for a seated party.
     * For an unseated party, occupied, cleaning, and unavailable tables keep their status.
     */
    public function cancel(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->cancelReservation($reservation, request()->user(), 'cancelled_by_staff');

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function arrive(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->arriveReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation marked as arrived.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Mark a reservation as no-show.
     *
     * Releases reserved preassigned tables, or all assigned tables for a seated party.
     * For an unseated party, occupied, cleaning, and unavailable tables keep their status.
     */
    public function noShow(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->noShowReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation marked as no-show.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function partiallyArrive(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->partiallyArriveReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation marked as partially arrived.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function leftMessage(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->leftMessageReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation marked as left message.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function runningLate(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->runningLateReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation marked as running late.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }
}
