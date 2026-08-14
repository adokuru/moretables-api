<?php

namespace App\Http\Controllers\Api\V1;

use App\BillingPlanSlug;
use App\Events\RestaurantShiftNoteUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantShiftNoteResource;
use App\Models\Restaurant;
use App\Models\RestaurantShiftNote;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Front of House / Shift Notes', weight: 38)]
class FrontOfHouseShiftNoteController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);
        $validated = $this->validateService($request);

        $notes = $restaurant->shiftNotes()
            ->with(['author', 'restaurant'])
            ->where('service_starts_at', $validated['service_starts_at'])
            ->where('service_ends_at', $validated['service_ends_at'])
            ->oldest()
            ->get();

        return response()->json(['data' => RestaurantShiftNoteResource::collection($notes)]);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.manage', $restaurant), 403);
        $this->abortUnlessPlanQualifies($restaurant);
        $validated = $this->validateService($request) + $request->validate([
            'restaurant_shift_id' => ['nullable', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if (! empty($validated['restaurant_shift_id'])) {
            abort_unless($restaurant->shifts()->whereKey($validated['restaurant_shift_id'])->exists(), 422);
        }

        $note = $restaurant->shiftNotes()->create([
            ...$validated,
            'created_by_user_id' => $request->user()->id,
        ])->load(['author', 'restaurant']);

        event(new RestaurantShiftNoteUpdated($note, 'created'));

        return response()->json([
            'message' => 'Shift note created successfully.',
            'note' => RestaurantShiftNoteResource::make($note),
        ], 201);
    }

    public function update(Request $request, Restaurant $restaurant, RestaurantShiftNote $shiftNote): JsonResponse
    {
        $this->authorizeMutation($request, $restaurant, $shiftNote);
        $this->abortUnlessPlanQualifies($restaurant);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $shiftNote->update($validated);
        $shiftNote->load(['author', 'restaurant']);
        event(new RestaurantShiftNoteUpdated($shiftNote, 'updated'));

        return response()->json([
            'message' => 'Shift note updated successfully.',
            'note' => RestaurantShiftNoteResource::make($shiftNote),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant, RestaurantShiftNote $shiftNote): JsonResponse
    {
        $this->authorizeMutation($request, $restaurant, $shiftNote);
        event(new RestaurantShiftNoteUpdated($shiftNote, 'deleted'));
        $shiftNote->delete();

        return response()->json(['message' => 'Shift note deleted successfully.']);
    }

    private function validateService(Request $request): array
    {
        $validated = $request->validate([
            'service_starts_at' => ['required', 'date'],
            'service_ends_at' => ['required', 'date', 'after:service_starts_at'],
        ]);

        return [
            'service_starts_at' => CarbonImmutable::parse($validated['service_starts_at'])->utc(),
            'service_ends_at' => CarbonImmutable::parse($validated['service_ends_at'])->utc(),
        ];
    }

    private function authorizeMutation(Request $request, Restaurant $restaurant, RestaurantShiftNote $note): void
    {
        abort_unless($note->restaurant_id === $restaurant->id, 404);
        abort_unless(
            $request->user()->id === $note->created_by_user_id
            || $request->user()->hasRestaurantPermission('restaurants.manage', $restaurant),
            403,
        );
    }

    /**
     * Pre-shift Report is Premium-only (docs/PLAN_PERMISSIONS.md). Reading existing
     * notes and deleting them stay allowed on any plan — only creating/editing new
     * content is gated.
     */
    private function abortUnlessPlanQualifies(Restaurant $restaurant): void
    {
        abort_unless(
            $restaurant->hasPlanAtLeast(BillingPlanSlug::Premium),
            403,
            'Upgrade to Premium to access the Pre-shift Report.',
        );
    }
}
