<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreRestaurantListItemRequest;
use App\Http\Requests\Customer\StoreRestaurantListRequest;
use App\Http\Requests\Customer\UpdateRestaurantListRequest;
use App\Http\Resources\RestaurantListResource;
use App\Models\Restaurant;
use App\Models\UserRestaurantList;
use App\Models\UserRestaurantListItem;
use App\RestaurantStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Customer Restaurant Lists', weight: 26)]
class CustomerRestaurantListController extends Controller
{
    /**
     * List the authenticated customer's restaurant lists.
     */
    public function index(Request $request): JsonResponse
    {
        $lists = $request->user()
            ->restaurantLists()
            ->withCount('items')
            ->with(['restaurants.cuisines', 'restaurants.media'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $lists->map(fn (UserRestaurantList $restaurantList): array => $this->serializeList($restaurantList, $request))->values(),
        ]);
    }

    /**
     * Create a new restaurant list for the authenticated customer.
     */
    public function store(StoreRestaurantListRequest $request): JsonResponse
    {
        $restaurantList = $request->user()->restaurantLists()->create($request->validated());
        $restaurantList->loadCount('items');
        $restaurantList->load(['restaurants.cuisines', 'restaurants.media']);

        return response()->json([
            'message' => 'Restaurant list created successfully.',
            'list' => $this->serializeList($restaurantList, $request),
        ], 201);
    }

    /**
     * Update one of the authenticated customer's restaurant lists.
     */
    public function update(UpdateRestaurantListRequest $request, UserRestaurantList $restaurantList): JsonResponse
    {
        $this->ensureOwnership($request, $restaurantList);

        $restaurantList->update($request->validated());
        $restaurantList->loadCount('items');
        $restaurantList->load(['restaurants.cuisines', 'restaurants.media']);

        return response()->json([
            'message' => 'Restaurant list updated successfully.',
            'list' => $this->serializeList($restaurantList, $request),
        ]);
    }

    /**
     * Delete one of the authenticated customer's restaurant lists.
     */
    public function destroy(Request $request, UserRestaurantList $restaurantList): JsonResponse
    {
        $this->ensureOwnership($request, $restaurantList);

        $restaurantList->delete();

        return response()->json([
            'message' => 'Restaurant list deleted successfully.',
        ]);
    }

    /**
     * Add a restaurant to one of the authenticated customer's lists.
     */
    public function addRestaurant(StoreRestaurantListItemRequest $request, UserRestaurantList $restaurantList): JsonResponse
    {
        $this->ensureOwnership($request, $restaurantList);

        $restaurant = Restaurant::query()->findOrFail($request->integer('restaurant_id'));
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $listItem = UserRestaurantListItem::query()->firstOrCreate(
            [
                'user_restaurant_list_id' => $restaurantList->id,
                'restaurant_id' => $restaurant->id,
            ],
            [
                'sort_order' => (int) ($request->validated()['sort_order'] ?? $restaurantList->items()->count()),
            ],
        );

        $restaurantList->loadCount('items');
        $restaurantList->load(['restaurants.cuisines', 'restaurants.media']);

        return response()->json([
            'message' => $listItem->wasRecentlyCreated
                ? 'Restaurant added to list successfully.'
                : 'Restaurant already exists in this list.',
            'list' => $this->serializeList($restaurantList, $request),
        ], $listItem->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a restaurant from one of the authenticated customer's lists.
     */
    public function removeRestaurant(Request $request, UserRestaurantList $restaurantList, Restaurant $restaurant): JsonResponse
    {
        $this->ensureOwnership($request, $restaurantList);

        UserRestaurantListItem::query()
            ->where('user_restaurant_list_id', $restaurantList->id)
            ->where('restaurant_id', $restaurant->id)
            ->delete();

        $restaurantList->loadCount('items');
        $restaurantList->load(['restaurants.cuisines', 'restaurants.media']);

        return response()->json([
            'message' => 'Restaurant removed from list successfully.',
            'list' => $this->serializeList($restaurantList, $request),
        ]);
    }

    protected function ensureOwnership(Request $request, UserRestaurantList $restaurantList): void
    {
        abort_unless($restaurantList->user_id === $request->user()->id, 404);
    }

    /**
     * @return array{id: int, name: string, description: ?string, is_private: bool, restaurants_count: int, restaurants: array<int, array<string, mixed>>, created_at: ?string, updated_at: ?string}
     */
    protected function serializeList(UserRestaurantList $restaurantList, Request $request): array
    {
        return [
            'id' => $restaurantList->id,
            'name' => $restaurantList->name,
            'description' => $restaurantList->description,
            'is_private' => (bool) $restaurantList->is_private,
            'restaurants_count' => (int) ($restaurantList->items_count ?? $restaurantList->items()->count()),
            'restaurants' => RestaurantListResource::collection(
                $restaurantList->restaurants
                    ->filter(fn (Restaurant $restaurant): bool => $restaurant->status === RestaurantStatus::Active)
                    ->values(),
            )->resolve($request),
            'created_at' => $restaurantList->created_at?->toIso8601String(),
            'updated_at' => $restaurantList->updated_at?->toIso8601String(),
        ];
    }
}
