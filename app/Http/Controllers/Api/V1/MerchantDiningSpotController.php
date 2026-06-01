<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreDiningSpotRequest;
use App\Http\Requests\Merchant\UpdateDiningSpotRequest;
use App\Http\Resources\DiningSpotResource;
use App\Models\DiningArea;
use App\Models\DiningSpot;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Floor Plan', weight: 32)]
class MerchantDiningSpotController extends Controller
{
    public function index(Request $request, Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $spots = $diningArea->spots()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(DiningSpotResource::collection($spots->load('tables')));
    }

    public function store(StoreDiningSpotRequest $request, Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $validated = $request->validated();

        $spot = $diningArea->spots()->create([
            'restaurant_id' => $restaurant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Dining spot created successfully.',
            'spot' => DiningSpotResource::make($spot->load('tables')),
        ], 201);
    }

    public function update(UpdateDiningSpotRequest $request, Restaurant $restaurant, DiningArea $diningArea, DiningSpot $spot): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);
        abort_unless($spot->dining_area_id === $diningArea->id, 404);

        $spot->update($request->validated());

        return response()->json([
            'message' => 'Dining spot updated successfully.',
            'spot' => DiningSpotResource::make($spot->refresh()->load('tables')),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant, DiningArea $diningArea, DiningSpot $spot): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);
        abort_unless($spot->dining_area_id === $diningArea->id, 404);

        // Detach tables before deleting (nullOnDelete handles it via FK, but be explicit)
        $spot->tables()->update(['dining_spot_id' => null]);
        $spot->delete();

        return response()->json(['message' => 'Dining spot deleted successfully.']);
    }
}
