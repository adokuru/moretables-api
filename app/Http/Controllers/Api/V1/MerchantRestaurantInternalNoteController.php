<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantInternalNote;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Onboarding', weight: 35)]
class MerchantRestaurantInternalNoteController extends Controller
{
    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'notes' => $restaurant->internalNotes->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'created_at' => $n->created_at,
                'updated_at' => $n->updated_at,
            ])->values(),
        ]);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        $note = $restaurant->internalNotes()->create($validated);

        return response()->json([
            'message' => 'Internal note created successfully.',
            'note' => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at,
                'updated_at' => $note->updated_at,
            ],
        ], 201);
    }

    public function update(Request $request, Restaurant $restaurant, RestaurantInternalNote $internalNote): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $internalNote->restaurant_id === (int) $restaurant->id, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        $internalNote->update($validated);

        return response()->json([
            'message' => 'Internal note updated successfully.',
            'note' => [
                'id' => $internalNote->id,
                'body' => $internalNote->body,
                'created_at' => $internalNote->created_at,
                'updated_at' => $internalNote->updated_at,
            ],
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantInternalNote $internalNote): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $internalNote->restaurant_id === (int) $restaurant->id, 404);

        $internalNote->delete();

        return response()->json(['message' => 'Internal note deleted successfully.']);
    }
}
