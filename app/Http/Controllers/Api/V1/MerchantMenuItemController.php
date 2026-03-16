<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantMenuItemRequest;
use App\Http\Requests\Merchant\UpdateRestaurantMenuItemRequest;
use App\Http\Resources\RestaurantMenuItemResource;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Services\AuditLogService;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Menu', weight: 34)]
class MerchantMenuItemController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected MediaLibraryService $mediaLibraryService,
    ) {}

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);

        $menuItems = $restaurant->menuItems()->with('media')->orderBy('section_name')->orderBy('sort_order')->get();

        return response()->json(RestaurantMenuItemResource::collection($menuItems));
    }

    public function store(StoreRestaurantMenuItemRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $validated = $request->validated();
        $menuItem = $restaurant->menuItems()->create([
            'section_name' => $validated['section_name'],
            'item_name' => $validated['item_name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'NGN',
            'sort_order' => $validated['sort_order'] ?? ((int) $restaurant->menuItems()->max('sort_order') + 1),
        ]);

        $this->mediaLibraryService->syncUploadedMedia($menuItem, $validated);

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
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $validated = $request->validated();

        $menuItem->update([
            ...collect($validated)->except([
                'featured_image',
                'featured_image_alt_text',
                'gallery_images',
                'gallery_image_alt_texts',
            ])->toArray(),
            'item_name' => $validated['item_name'] ?? $menuItem->item_name,
        ]);

        $this->mediaLibraryService->syncUploadedMedia($menuItem, $validated);

        return response()->json([
            'message' => 'Menu item updated successfully.',
            'menu_item' => RestaurantMenuItemResource::make($menuItem->refresh()->load('media')),
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $menuItem->delete();

        return response()->json([
            'message' => 'Menu item deleted successfully.',
        ]);
    }
}
