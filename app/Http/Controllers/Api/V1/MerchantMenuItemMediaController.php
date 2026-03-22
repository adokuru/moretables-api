<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\ReorderMediaRequest;
use App\Http\Requests\Merchant\UpdateMediaAssetRequest;
use App\Http\Requests\Merchant\UploadModelMediaRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Group('Merchant Menu', weight: 34)]
class MerchantMenuItemMediaController extends Controller
{
    public function __construct(protected MediaLibraryService $mediaLibraryService) {}

    public function store(UploadModelMediaRequest $request, Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $this->mediaLibraryService->syncUploadedMedia($menuItem, $request->validated());

        $featuredImage = $menuItem->getFirstMedia('featured');

        return response()->json([
            'message' => 'Menu item media uploaded successfully.',
            'featured_image' => $featuredImage ? MediaAssetResource::make($featuredImage) : null,
            'gallery_images' => MediaAssetResource::collection($menuItem->load('media')->media->where('collection_name', 'gallery')->sortBy('order_column')->values()),
        ], 201);
    }

    public function update(UpdateMediaAssetRequest $request, Restaurant $restaurant, RestaurantMenuItem $menuItem, Media $media): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $updatedMedia = $this->mediaLibraryService->updateMedia($menuItem, $media, $request->validated('alt_text'));

        return response()->json([
            'message' => 'Menu item media updated successfully.',
            'media' => MediaAssetResource::make($updatedMedia),
        ]);
    }

    public function reorder(ReorderMediaRequest $request, Restaurant $restaurant, RestaurantMenuItem $menuItem): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $this->mediaLibraryService->reorderGallery($menuItem, $request->validated('media_ids'));

        return response()->json([
            'message' => 'Menu item gallery reordered successfully.',
            'gallery_images' => MediaAssetResource::collection($menuItem->refresh()->load('media')->media->where('collection_name', 'gallery')->sortBy('order_column')->values()),
        ]);
    }

    public function feature(Restaurant $restaurant, RestaurantMenuItem $menuItem, Media $media): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $featuredMedia = $this->mediaLibraryService->featureMedia($menuItem, $media);

        return response()->json([
            'message' => 'Menu item featured image updated successfully.',
            'featured_image' => MediaAssetResource::make($featuredMedia),
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantMenuItem $menuItem, Media $media): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($menuItem->restaurant_id === $restaurant->id, 404);

        $this->mediaLibraryService->deleteMedia($menuItem, $media);

        return response()->json([
            'message' => 'Menu item media deleted successfully.',
        ]);
    }
}
