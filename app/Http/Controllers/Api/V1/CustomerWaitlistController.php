<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waitlist\RespondToWaitlistEntryRequest;
use App\Http\Requests\Waitlist\StoreWaitlistEntryRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\Restaurant;
use App\Models\WaitlistEntry;
use App\Services\ReservationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Customer Waitlist', weight: 22)]
class CustomerWaitlistController extends Controller
{
    public function __construct(protected ReservationService $reservationService) {}

    public function index(): JsonResponse
    {
        $entries = request()->user()
            ->waitlistEntries()
            ->with(['restaurant.cuisines', 'restaurant.media', 'reservation.reservationGuests'])
            ->latest('preferred_starts_at')
            ->paginate(15);

        return response()->json(WaitlistEntryResource::collection($entries));
    }

    public function store(StoreWaitlistEntryRequest $request): JsonResponse
    {
        $restaurant = Restaurant::query()->findOrFail($request->integer('restaurant_id'));
        $entry = $this->reservationService->createWaitlistEntry(
            restaurant: $restaurant,
            actor: $request->user(),
            attributes: $request->validated(),
            customer: $request->user(),
        );

        return response()->json([
            'message' => 'Waitlist entry created successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ], 201);
    }

    public function accept(RespondToWaitlistEntryRequest $request, WaitlistEntry $waitlistEntry): JsonResponse
    {
        $reservation = $this->reservationService->acceptWaitlistEntry($waitlistEntry, $request->user());

        return response()->json([
            'message' => 'Waitlist offer accepted successfully.',
            'reservation' => ReservationResource::make($reservation),
        ]);
    }

    public function decline(RespondToWaitlistEntryRequest $request, WaitlistEntry $waitlistEntry): JsonResponse
    {
        $entry = $this->reservationService->declineWaitlistEntry($waitlistEntry, $request->user());

        return response()->json([
            'message' => 'Waitlist offer declined successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }
}
