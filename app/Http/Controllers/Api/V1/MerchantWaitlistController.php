<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\AssignReservationTableRequest;
use App\Http\Requests\Merchant\NotifyWaitlistEntryRequest;
use App\Http\Requests\Merchant\StoreMerchantWaitlistEntryRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\GuestContact;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\ReservationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Waitlist', weight: 38)]
class MerchantWaitlistController extends Controller
{
    public function __construct(protected ReservationService $reservationService) {}

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $entries = $restaurant->waitlistEntries()
            ->with(['restaurant', 'reservation', 'user', 'guestContact'])
            ->latest('preferred_starts_at')
            ->paginate(20);

        return response()->json(WaitlistEntryResource::collection($entries));
    }

    public function store(StoreMerchantWaitlistEntryRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $guestContact = null;
        if (! empty($request->validated('guest_contact')) && ! $request->filled('user_id')) {
            $guestContact = GuestContact::query()->create([
                'restaurant_id' => $restaurant->id,
                'first_name' => $request->input('guest_contact.first_name'),
                'last_name' => $request->input('guest_contact.last_name'),
                'email' => $request->input('guest_contact.email'),
                'phone' => $request->input('guest_contact.phone'),
                'is_temporary' => true,
            ]);
        }

        $entry = $this->reservationService->createWaitlistEntry(
            restaurant: $restaurant,
            actor: $request->user(),
            attributes: $request->validated(),
            customer: $request->filled('user_id') ? User::query()->findOrFail($request->integer('user_id')) : null,
            guestContact: $guestContact,
        );

        return response()->json([
            'message' => 'Waitlist entry created successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ], 201);
    }

    public function notify(NotifyWaitlistEntryRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $entry = $this->reservationService->notifyWaitlistEntry(
            entry: $waitlistEntry,
            actor: $request->user(),
            expiresInMinutes: $request->integer('expires_in_minutes', 15),
        );

        return response()->json([
            'message' => 'Waitlist guest notified successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    public function assignTable(AssignReservationTableRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $table = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->findOrFail($request->integer('restaurant_table_id'));

        $reservation = $this->reservationService->assignWaitlistEntryToTable($waitlistEntry, $table, $request->user());

        return response()->json([
            'message' => 'Waitlist entry assigned to a table successfully.',
            'reservation' => ReservationResource::make($reservation),
        ]);
    }
}
