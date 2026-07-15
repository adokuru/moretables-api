<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WaitlistEntryUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\AssignReservationTableRequest;
use App\Http\Requests\Merchant\NotifyWaitlistEntryRequest;
use App\Http\Requests\Merchant\StoreMerchantWaitlistEntryRequest;
use App\Http\Requests\Merchant\UpdateMerchantWaitlistEntryRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\GuestContact;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityAlertService;
use App\Services\ReservationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Waitlist', weight: 38)]
class MerchantWaitlistController extends Controller
{
    public function __construct(
        protected ReservationService $reservationService,
        protected AvailabilityAlertService $availabilityAlertService,
    ) {}

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);

        $entries = $restaurant->waitlistEntries()
            ->with(['restaurant', 'reservation.reservationGuests', 'table', 'user', 'guestContact'])
            ->latest('preferred_starts_at')
            ->paginate(20);

        return response()->json(WaitlistEntryResource::collection($entries));
    }

    public function store(StoreMerchantWaitlistEntryRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);

        $guestContact = null;
        if (! empty($request->validated('guest_contact')) && ! $request->filled('user_id')) {
            $guestContact = $request->filled('guest_contact.phone')
                ? GuestContact::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->where('phone', $request->input('guest_contact.phone'))
                    ->where('is_temporary', false)
                    ->first()
                : null;

            if (! $guestContact) {
                $guestContact = GuestContact::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'first_name' => $request->input('guest_contact.first_name'),
                    'last_name' => $request->input('guest_contact.last_name'),
                    'email' => $request->input('guest_contact.email'),
                    'phone' => $request->input('guest_contact.phone'),
                    'is_temporary' => false,
                ]);
            }

            $this->updateGuestContact($guestContact, $request->validated('guest_contact'));
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

    public function show(Request $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        return WaitlistEntryResource::make($waitlistEntry->load(['restaurant', 'reservation', 'table', 'user', 'guestContact']));
    }

    public function update(UpdateMerchantWaitlistEntryRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $validated = $request->validated();
        $guestContact = $validated['guest_contact'] ?? null;
        unset($validated['guest_contact']);

        if ($guestContact && $waitlistEntry->guestContact) {
            $this->updateGuestContact($waitlistEntry->guestContact, $guestContact);
        }

        $waitlistEntry->update($validated);
        $waitlistEntry->refresh()->load(['restaurant', 'reservation', 'table', 'user', 'guestContact']);
        event(new WaitlistEntryUpdated($waitlistEntry, 'updated'));

        return response()->json([
            'message' => 'Waitlist entry updated successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($waitlistEntry),
        ]);
    }

    public function notify(NotifyWaitlistEntryRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $entry = $waitlistEntry->isAvailabilityAlert()
            ? $this->availabilityAlertService->notifyNow($waitlistEntry, $request->user())
            : $this->reservationService->notifyWaitlistEntry(
                entry: $waitlistEntry,
                actor: $request->user(),
                expiresInMinutes: $request->integer('expires_in_minutes', 15),
            );

        return response()->json([
            'message' => 'Waitlist guest notified successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    public function arrive(Request $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $entry = $this->reservationService->arriveWaitlistEntry($waitlistEntry, $request->user());

        return response()->json([
            'message' => 'Waitlist guest marked arrived.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    public function partiallyArrive(Request $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $entry = $this->reservationService->partiallyArriveWaitlistEntry($waitlistEntry, $request->user());

        return response()->json([
            'message' => 'Waitlist guest marked partially arrived.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    public function assignTable(AssignReservationTableRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
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

    /**
     * Pre-assign a table without seating the waitlist party.
     *
     * The entry remains in its current waitlist status. Use `assign-table` only
     * when the party should be converted to a reservation and seated.
     */
    public function preassignTable(AssignReservationTableRequest $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $table = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->findOrFail($request->integer('restaurant_table_id'));

        $entry = $this->reservationService->preassignWaitlistEntryToTable($waitlistEntry, $table, $request->user());

        return response()->json([
            'message' => 'Table pre-assigned without seating the waitlist party.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    public function cancel(Request $request, Restaurant $restaurant, WaitlistEntry $waitlistEntry): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);
        abort_unless($waitlistEntry->restaurant_id === $restaurant->id, 404);

        $entry = $waitlistEntry->isAvailabilityAlert()
            ? $this->availabilityAlertService->cancel($waitlistEntry, $request->user())
            : $this->reservationService->cancelWaitlistEntry($waitlistEntry, $request->user());

        return response()->json([
            'message' => 'Waitlist entry cancelled successfully.',
            'waitlist_entry' => WaitlistEntryResource::make($entry),
        ]);
    }

    private function updateGuestContact(GuestContact $guestContact, array $contact): void
    {
        $seatingPreference = $contact['seating_preference'] ?? null;
        unset($contact['seating_preference']);
        $guestContact->fill(array_filter($contact, fn ($value) => $value !== null));
        if ($seatingPreference !== null) {
            $guestContact->preferences = [
                ...($guestContact->preferences ?? []),
                'seating_preference' => $seatingPreference,
            ];
        }
        $guestContact->save();
    }
}
