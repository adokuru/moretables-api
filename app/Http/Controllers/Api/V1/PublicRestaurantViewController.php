<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRestaurantViewRequest;
use App\Models\Restaurant;
use App\Models\RestaurantView;
use App\Services\PerformanceCacheService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

#[Group('Public Restaurants', weight: 4)]
class PublicRestaurantViewController extends Controller
{
    public function __construct(protected PerformanceCacheService $performanceCache) {}

    /**
     * Record a restaurant detail view for discovery ranking. Repeated views from the same user, session, or IP within 30 minutes return a deduplicated success response.
     */
    #[Response(200, type: 'array{message: string, deduplicated: bool}')]
    #[Response(201, type: 'array{message: string, view_id: int, deduplicated: bool}')]
    #[Response(429, type: 'array{message: string}')]
    public function store(StoreRestaurantViewRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->isPubliclyListed(), 404);

        $userId = $request->user('sanctum')?->id;
        $fingerprint = $userId
            ? "user:{$userId}"
            : 'visitor:'.($request->input('session_id') ?: $request->ip());
        $key = $this->performanceCache->key('restaurant-view', (string) $restaurant->id, hash('sha256', $fingerprint));

        if (! Cache::add($key, true, (int) config('performance.cache.ttls.view_deduplication'))) {
            return response()->json([
                'message' => 'Restaurant view already recorded recently.',
                'deduplicated' => true,
            ]);
        }

        $view = RestaurantView::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $userId,
            'platform' => $request->input('platform'),
            'session_id' => $request->input('session_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Restaurant view recorded successfully.',
            'view_id' => $view->id,
            'deduplicated' => false,
        ], 201);
    }
}
