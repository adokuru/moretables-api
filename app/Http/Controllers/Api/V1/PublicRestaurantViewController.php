<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRestaurantViewRequest;
use App\Models\Restaurant;
use App\Models\RestaurantView;
use App\RestaurantStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Public Restaurants', weight: 4)]
class PublicRestaurantViewController extends Controller
{
    /**
     * Record a restaurant detail view for discovery ranking.
     */
    public function store(StoreRestaurantViewRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $view = RestaurantView::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user('sanctum')?->id,
            'platform' => $request->input('platform'),
            'session_id' => $request->input('session_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Restaurant view recorded successfully.',
            'view_id' => $view->id,
        ], 201);
    }
}
