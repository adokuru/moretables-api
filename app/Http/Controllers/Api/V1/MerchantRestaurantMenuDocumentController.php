<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantMenuDocumentRequest;
use App\Http\Resources\RestaurantDetailResource;
use App\Models\Restaurant;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Restaurant Profile', weight: 31)]
class MerchantRestaurantMenuDocumentController extends Controller
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
    ) {}

    public function store(StoreRestaurantMenuDocumentRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $this->mediaLibraryService->syncMenuDocument($restaurant, $request->file('menu_document'));

        $restaurant->load('media');

        return response()->json([
            'message' => 'Menu document uploaded successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant),
        ]);
    }
}
