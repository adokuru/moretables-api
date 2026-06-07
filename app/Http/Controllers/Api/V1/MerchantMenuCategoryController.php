<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreMenuCategoryRequest;
use App\Http\Requests\Merchant\UpdateMenuCategoryRequest;
use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Menu', weight: 36)]
class MerchantMenuCategoryController extends Controller
{
    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'categories' => $restaurant->menuCategories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sort_order' => $c->sort_order,
            ])->values(),
        ]);
    }

    public function store(StoreMenuCategoryRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) $restaurant->menuCategories()->max('sort_order') + 1;
        }

        $category = $restaurant->menuCategories()->create($validated);

        return response()->json([
            'message' => 'Menu category created successfully.',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
            ],
        ], 201);
    }

    public function update(UpdateMenuCategoryRequest $request, Restaurant $restaurant, RestaurantMenuCategory $menuCategory): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $menuCategory->restaurant_id === (int) $restaurant->id, 404);

        $menuCategory->update($request->validated());

        return response()->json([
            'message' => 'Menu category updated successfully.',
            'category' => [
                'id' => $menuCategory->id,
                'name' => $menuCategory->name,
                'sort_order' => $menuCategory->sort_order,
            ],
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantMenuCategory $menuCategory): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $menuCategory->restaurant_id === (int) $restaurant->id, 404);

        $menuCategory->delete();

        return response()->json(['message' => 'Menu category deleted successfully.']);
    }
}
