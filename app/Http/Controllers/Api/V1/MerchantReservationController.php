<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\AssignReservationTableRequest;
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
            ->with(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests'])
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
     * Create a reservation. A 422 is returned when the requested time is outside effective booking hours or availability changes while processing.
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

        return ReservationResource::make($reservation->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']));
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

    /**
     * Assign the requested table after rechecking it inside the reservation lock. A retryable 422 is returned if it is unavailable.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function assignTable(AssignReservationTableRequest $request, Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $table = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->findOrFail($request->integer('restaurant_table_id'));

        $updatedReservation = $this->reservationService->assignTable($reservation, $table, $request->user());

        return response()->json([
            'message' => 'Reservation table assigned successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function seat(Restaurant $restaurant, Reservation $reservation): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($reservation->restaurant_id === $restaurant->id, 404);

        $updatedReservation = $this->reservationService->seatReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation seated successfully.',
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
