<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestContactResource;
use App\Http\Resources\ReservationResource;
use App\Models\GuestContact;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Guestbook is a restaurant's address book — a persistent record of every
 * guest who has visited or made a reservation, enriched with contact details,
 * notable dates, dining preferences, and visit history.
 */
#[Group('Guestbook', weight: 40)]
class GuestbookController extends Controller
{
    /**
     * List all guests.
     *
     * Returns a paginated list of non-temporary guest contacts for the restaurant,
     * ordered alphabetically by first name. Supports name, email, and phone search
     * via the `search_term` query parameter.
     *
     * The `total_guests` field in the response reflects the total count across all
     * pages (unfiltered), matching the "Total number of guests: N" header in the UI.
     */
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $request->validate([
            'search_term' => ['nullable', 'string', 'max:100'],
        ]);

        $totalGuests = $restaurant->guestContacts()->where('is_temporary', false)->count();

        $guests = $restaurant->guestContacts()
            ->where('is_temporary', false)
            ->when($request->filled('search_term'), function ($query) use ($request): void {
                $term = $request->string('search_term')->toString();
                $query->where(function ($q) use ($term): void {
                    $q->where('first_name', 'LIKE', "%{$term}%")
                        ->orWhere('last_name', 'LIKE', "%{$term}%")
                        ->orWhere('email', 'LIKE', "%{$term}%")
                        ->orWhere('phone', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(20);

        $response = GuestContactResource::collection($guests)->response()->getData(true);

        return response()->json([
            'total_guests' => $totalGuests,
            ...$response,
        ]);
    }

    /**
     * Create a guest.
     *
     * Manually adds a new guest to the restaurant's guestbook. Use this when
     * staff want to pre-register a guest without an existing reservation.
     */
    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'birthday' => ['nullable', 'date_format:Y-m-d'],
            'wedding_anniversary' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $guest = $restaurant->guestContacts()->create([
            ...$validated,
            'is_temporary' => false,
        ]);

        return response()->json([
            'message' => 'Guest created successfully.',
            'guest' => GuestContactResource::make($guest),
        ], 201);
    }

    /**
     * Get guest personal details.
     *
     * Returns the guest's contact information and notable dates (birthday,
     * wedding anniversary) — the "Personal details" tab in the Guestbook UI.
     */
    public function show(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        return response()->json([
            'guest' => GuestContactResource::make($guestContact),
        ]);
    }

    /**
     * Update guest personal details.
     *
     * Updates contact info, notable dates, and marketing opt-in status.
     * Maps to the "Personal details" tab save action and the Marketing Opt-In toggle.
     */
    public function update(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'wedding_anniversary' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $guestContact->update($validated);

        return response()->json([
            'message' => 'Guest updated successfully.',
            'guest' => GuestContactResource::make($guestContact->fresh()),
        ]);
    }

    /**
     * Get guest preferences.
     *
     * Returns the guest's dining preferences — the "Preferences" tab.
     * Preferences are stored as a flexible key/value structure allowing restaurants
     * to capture seating, dietary, and communication preferences.
     */
    public function showPreferences(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        return response()->json([
            'guest_id' => $guestContact->id,
            'preferences' => $this->formatPreferences($guestContact->preferences ?? []),
        ]);
    }

    /**
     * Update guest preferences.
     *
     * Updates the guest's dining preferences. All fields are optional — only
     * supplied keys will be merged into the existing preferences.
     */
    public function updatePreferences(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        $validated = $request->validate([
            'seating_preference' => ['nullable', 'string', 'max:100'],
            'dietary_restrictions' => ['nullable', 'array'],
            'dietary_restrictions.*' => ['string', 'max:100'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:100'],
            'favorite_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'preferred_server' => ['nullable', 'string', 'max:100'],
            'communication_preference' => ['nullable', 'string', 'in:email,sms,none'],
            'special_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $current = $guestContact->preferences ?? [];
        $preferences = array_merge($current, array_filter($validated, fn ($v) => $v !== null));

        $guestContact->update(['preferences' => $preferences]);

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'guest_id' => $guestContact->id,
            'preferences' => $this->formatPreferences($guestContact->fresh()->preferences ?? []),
        ]);
    }

    /**
     * Get guest visit history.
     *
     * Returns the guest's past and upcoming reservations and waitlist entries
     * at this restaurant — the "History" tab in the Guestbook UI.
     *
     * Includes summary stats: total visits, total covers, and last visit date.
     */
    public function history(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        $reservations = $guestContact->reservations()
            ->with(['table', 'restaurant'])
            ->orderByDesc('starts_at')
            ->paginate(20);

        $completedReservations = $guestContact->reservations()
            ->where('status', 'completed')
            ->get(['party_size', 'completed_at']);

        return response()->json([
            'guest_id' => $guestContact->id,
            'stats' => [
                'total_visits' => $completedReservations->count(),
                'total_covers' => $completedReservations->sum('party_size'),
                'last_visit_at' => $completedReservations->sortByDesc('completed_at')->first()?->completed_at?->toIso8601String(),
            ],
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }

    /**
     * Delete a guest.
     *
     * Permanently removes the guest contact record from the guestbook.
     * Existing reservations and waitlist entries are not deleted but will
     * lose their guest contact association.
     */
    public function destroy(Request $request, Restaurant $restaurant, GuestContact $guestContact): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        abort_unless($guestContact->restaurant_id === $restaurant->id, 404);

        $guestContact->delete();

        return response()->json([
            'message' => 'Guest removed from guestbook.',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                             //
    // ------------------------------------------------------------------ //

    private function formatPreferences(array $preferences): array
    {
        return [
            'seating_preference' => $preferences['seating_preference'] ?? null,
            'dietary_restrictions' => $preferences['dietary_restrictions'] ?? [],
            'allergies' => $preferences['allergies'] ?? [],
            'favorite_table_id' => $preferences['favorite_table_id'] ?? null,
            'preferred_server' => $preferences['preferred_server'] ?? null,
            'communication_preference' => $preferences['communication_preference'] ?? null,
            'special_notes' => $preferences['special_notes'] ?? null,
        ];
    }
}
