<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\StoreReservationRequest;
use App\Http\Requests\Reservations\UpdateReservationGuestsRequest;
use App\Http\Requests\Reservations\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Services\ReservationService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Customer Reservations', weight: 20)]
class CustomerReservationController extends Controller
{
    public function __construct(protected ReservationService $reservationService) {}

    public function index(): JsonResponse
    {
        $user = request()->user();
        $reservations = $user->reservations()
            ->with(['restaurant.cuisines', 'restaurant.media', 'table', 'reservationGuests'])
            ->latest('starts_at')
            ->paginate(15);

        return response()->json(ReservationResource::collection($reservations));
    }

    /**
     * Create a reservation. Set `use_points` to true to deduct the customer's entire available points balance and record it on the reservation. A 422 is returned when no points are available, the requested time is outside effective booking hours, or availability changes while processing. The guest and restaurant owner are notified by email.
     *
     * Slots under a card-hold cancellation policy require `card_hold_reference`: either a `rch_` reference from the card-hold endpoint, or the reference of a Paystack transaction the frontend collected itself. In the latter case the transaction is verified here, the card authorization is saved, and the verification charge is refunded. A 422 is returned when the reference cannot be verified or has already been used.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = Restaurant::query()->with('policy')->findOrFail($request->integer('restaurant_id'));

        $reservation = $this->reservationService->createCustomerReservation($user, $restaurant, $request->validated());

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => ReservationResource::make($reservation),
        ], 201);
    }

    public function show(Reservation $reservation): ReservationResource
    {
        abort_unless($reservation->user_id === request()->user()->id, 404);

        return ReservationResource::make($reservation->load(['restaurant.cuisines', 'restaurant.media', 'table', 'reservationGuests']));
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 404);
        $this->ensureModificationAllowed($reservation);

        $updatedReservation = $this->reservationService->updateReservation($reservation, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Reservation updated successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->user_id === request()->user()->id, 404);
        $this->ensureModificationAllowed($reservation);

        $cancelledReservation = $this->reservationService->cancelReservation($reservation, request()->user());

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'reservation' => ReservationResource::make($cancelledReservation),
        ]);
    }

    public function updateGuests(UpdateReservationGuestsRequest $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 404);
        $this->ensureModificationAllowed($reservation);

        $guests = $request->validated('guests');
        $updatedReservation = count($guests) === 1
            ? $this->reservationService->addReservationGuests($reservation, $request->user(), $guests)
            : $this->reservationService->updateReservationGuests($reservation, $request->user(), $guests);

        return response()->json([
            'message' => 'Reservation guests updated successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Merge additional guests into the reservation. Existing guests (by email) are updated if the same email appears again.
     * For a full replace, use {@see updateGuests} (PUT).
     */
    public function addGuests(UpdateReservationGuestsRequest $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 404);
        $this->ensureModificationAllowed($reservation);

        $updatedReservation = $this->reservationService->addReservationGuests($reservation, $request->user(), $request->validated('guests'));

        return response()->json([
            'message' => 'Reservation guests added successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    /**
     * Remove one additional guest by `reservation_guests.id`. To clear every additional guest, use {@see updateGuests} with an empty `guests` array.
     */
    public function removeGuest(Reservation $reservation, ReservationGuest $reservationGuest): JsonResponse
    {
        abort_unless($reservation->user_id === request()->user()->id, 404);
        abort_unless($reservationGuest->reservation_id === $reservation->id, 404);
        $this->ensureModificationAllowed($reservation);

        $updatedReservation = $this->reservationService->removeReservationGuest(
            $reservation,
            request()->user(),
            $reservationGuest,
        );

        return response()->json([
            'message' => 'Reservation guest removed successfully.',
            'reservation' => ReservationResource::make($updatedReservation),
        ]);
    }

    protected function ensureModificationAllowed(Reservation $reservation): void
    {
        $cutoffHours = $reservation->restaurant->policy?->cancellation_cutoff_hours ?? 1;
        abort_if(Carbon::parse($reservation->starts_at)->subHours($cutoffHours)->isPast(), 422, 'The modification and cancellation period for this reservation has ended. Please contact the restaurant for help.');
    }
}
