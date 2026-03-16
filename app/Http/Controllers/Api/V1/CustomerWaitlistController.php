<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waitlist\StoreWaitlistEntryRequest;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\Restaurant;
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
            ->with(['restaurant.cuisines', 'restaurant.media', 'reservation'])
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
}
