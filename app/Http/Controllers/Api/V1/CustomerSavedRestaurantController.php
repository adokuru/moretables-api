<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantListResource;
use App\Models\Restaurant;
use App\Models\SavedRestaurant;
use App\RestaurantStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Customer Saved Restaurants', weight: 24)]
class CustomerSavedRestaurantController extends Controller
{
    /**
     * List the authenticated customer's saved restaurants.
     */
    public function index(Request $request): JsonResponse
    {
        $savedRestaurants = $request->user()
            ->savedRestaurantEntries()
            ->with(['restaurant.cuisines', 'restaurant.media'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => RestaurantListResource::collection(
                $savedRestaurants->getCollection()
                    ->pluck('restaurant')
                    ->filter()
                    ->values(),
            )->resolve($request),
            'meta' => [
                'current_page' => $savedRestaurants->currentPage(),
                'last_page' => $savedRestaurants->lastPage(),
                'per_page' => $savedRestaurants->perPage(),
                'total' => $savedRestaurants->total(),
            ],
        ]);
    }

    /**
     * Save a restaurant to the authenticated customer's account.
     */
    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $savedRestaurant = SavedRestaurant::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'restaurant_id' => $restaurant->id,
        ]);

        return response()->json([
            'message' => $savedRestaurant->wasRecentlyCreated
                ? 'Restaurant saved successfully.'
                : 'Restaurant was already saved.',
            'saved_restaurant' => [
                'id' => $savedRestaurant->id,
                'restaurant_id' => $restaurant->id,
                'saved_at' => $savedRestaurant->created_at?->toIso8601String(),
            ],
        ], $savedRestaurant->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a restaurant from the authenticated customer's saved items.
     */
    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        SavedRestaurant::query()
            ->where('user_id', $request->user()->id)
            ->where('restaurant_id', $restaurant->id)
            ->delete();

        return response()->json([
            'message' => 'Restaurant removed from saved items successfully.',
        ]);
    }
}
