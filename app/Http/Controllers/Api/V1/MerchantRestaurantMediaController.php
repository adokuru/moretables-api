<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\ReorderMediaRequest;
use App\Http\Requests\Merchant\UpdateMediaAssetRequest;
use App\Http\Requests\Merchant\UploadModelMediaRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Restaurant;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Group('Merchant Restaurant Profile', weight: 30)]
class MerchantRestaurantMediaController extends Controller
{
    public function __construct(protected MediaLibraryService $mediaLibraryService) {}

    public function store(UploadModelMediaRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $this->mediaLibraryService->syncUploadedMedia($restaurant, $request->validated());

        $featuredImage = $restaurant->getFirstMedia('featured');

        return response()->json([
            'message' => 'Restaurant media uploaded successfully.',
            'featured_image' => $featuredImage ? MediaAssetResource::make($featuredImage) : null,
            'gallery_images' => MediaAssetResource::collection($restaurant->load('media')->media->where('collection_name', 'gallery')->sortBy('order_column')->values()),
        ], 201);
    }

    public function update(UpdateMediaAssetRequest $request, Restaurant $restaurant, Media $media): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $updatedMedia = $this->mediaLibraryService->updateMedia($restaurant, $media, $request->validated('alt_text'));

        return response()->json([
            'message' => 'Restaurant media updated successfully.',
            'media' => MediaAssetResource::make($updatedMedia),
        ]);
    }

    public function reorder(ReorderMediaRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $this->mediaLibraryService->reorderGallery($restaurant, $request->validated('media_ids'));

        return response()->json([
            'message' => 'Restaurant gallery reordered successfully.',
            'gallery_images' => MediaAssetResource::collection($restaurant->refresh()->load('media')->media->where('collection_name', 'gallery')->sortBy('order_column')->values()),
        ]);
    }

    public function feature(Restaurant $restaurant, Media $media): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);

        $featuredMedia = $this->mediaLibraryService->featureMedia($restaurant, $media);

        return response()->json([
            'message' => 'Restaurant featured image updated successfully.',
            'featured_image' => MediaAssetResource::make($featuredMedia),
        ]);
    }

    public function destroy(Restaurant $restaurant, Media $media): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);

        $this->mediaLibraryService->deleteMedia($restaurant, $media);

        return response()->json([
            'message' => 'Restaurant media deleted successfully.',
        ]);
    }
}
