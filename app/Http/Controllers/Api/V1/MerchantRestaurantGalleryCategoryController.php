<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreGalleryCategoryRequest;
use App\Http\Requests\Merchant\UpdateGalleryCategoryRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Restaurant;
use App\Models\RestaurantGalleryCategory;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Gallery', weight: 32)]
class MerchantRestaurantGalleryCategoryController extends Controller
{
    public function __construct(protected MediaLibraryService $mediaLibraryService) {}

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'categories' => $restaurant->galleryCategories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sort_order' => $c->sort_order,
            ])->values(),
        ]);
    }

    public function store(StoreGalleryCategoryRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) $restaurant->galleryCategories()->max('sort_order') + 1;
        }

        $category = $restaurant->galleryCategories()->create($validated);

        return response()->json([
            'message' => 'Gallery category created successfully.',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
            ],
        ], 201);
    }

    public function update(UpdateGalleryCategoryRequest $request, Restaurant $restaurant, RestaurantGalleryCategory $galleryCategory): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $galleryCategory->restaurant_id === (int) $restaurant->id, 404);

        $galleryCategory->update($request->validated());

        return response()->json([
            'message' => 'Gallery category updated successfully.',
            'category' => [
                'id' => $galleryCategory->id,
                'name' => $galleryCategory->name,
                'sort_order' => $galleryCategory->sort_order,
            ],
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantGalleryCategory $galleryCategory): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless((int) $galleryCategory->restaurant_id === (int) $restaurant->id, 404);

        $this->mediaLibraryService->clearGalleryCategoryFromMedia($restaurant, $galleryCategory->id);
        $galleryCategory->delete();

        return response()->json(['message' => 'Gallery category deleted. Photos have been moved to uncategorised.']);
    }
}
