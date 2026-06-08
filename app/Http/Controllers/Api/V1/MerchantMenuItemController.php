<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantMenuItemRequest;
use App\Http\Requests\Merchant\UpdateRestaurantMenuItemRequest;
use App\Http\Resources\RestaurantMenuItemResource;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Services\AuditLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Menu', weight: 34)]
class MerchantMenuItemController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $query = $restaurant->menuItems()->with('media')->orderBy('section_name')->orderBy('sort_order');

        if ($sectionName = request()->query('section_name')) {
            $query->where('section_name', $sectionName);
        }

        return response()->json(RestaurantMenuItemResource::collection($query->get()));
    }

    public function grouped(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $items = $restaurant->menuItems()->with('media')->orderBy('section_name')->orderBy('sort_order')->get();

        $grouped = $items->groupBy('section_name')->map(function ($categoryItems, string $category) {
            return [
                'category' => $category,
                'data' => RestaurantMenuItemResource::collection($categoryItems)->resolve(),
            ];
        })->values();

        return response()->json($grouped);
    }

    public function show(Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        abort_unless((int) $menuItem->restaurant_id === (int) $restaurant->id, 404);

        return response()->json([
            'menu_item' => RestaurantMenuItemResource::make($menuItem->load('media')),
        ]);
    }

    public function store(StoreRestaurantMenuItemRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();
        $menuItem = $restaurant->menuItems()->create([
            'section_name' => $validated['section_name'],
            'item_name' => $validated['item_name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'NGN',
            'sort_order' => $validated['sort_order'] ?? ((int) $restaurant->menuItems()->max('sort_order') + 1),
        ]);

        $this->auditLogService->log(
            action: 'menu_item.created',
            actor: $request->user(),
            auditable: $menuItem,
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Restaurant menu item created',
        );

        return response()->json([
            'message' => 'Menu item created successfully.',
            'menu_item' => RestaurantMenuItemResource::make($menuItem->load('media')),
        ], 201);
    }

    public function update(UpdateRestaurantMenuItemRequest $request, Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $validated = $request->validated();

        $menuItem->update([
            ...$validated,
            'item_name' => $validated['item_name'] ?? $menuItem->item_name,
        ]);

        return response()->json([
            'message' => 'Menu item updated successfully.',
            'menu_item' => RestaurantMenuItemResource::make($menuItem->refresh()->load('media')),
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $menuItem->delete();

        return response()->json([
            'message' => 'Menu item deleted successfully.',
        ]);
    }
}
