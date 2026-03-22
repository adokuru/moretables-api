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
use App\Services\ReservationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Reservations', weight: 36)]
class MerchantReservationController extends Controller
{
    public function __construct(protected ReservationService $reservationService) {}

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $reservations = $restaurant->reservations()
            ->with(['restaurant', 'table', 'user', 'guestContact'])
            ->when($request->filled('date'), fn ($query) => $query->whereDate('starts_at', $request->string('date')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json(ReservationResource::collection($reservations));
    }

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

        return ReservationResource::make($reservation->load(['restaurant', 'table', 'user', 'guestContact']));
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
}
