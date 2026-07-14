<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\RestaurantRewardRuleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Reward Rules', weight: 37)]
class MerchantRestaurantRewardStatusController extends Controller
{
    /**
     * Report whether the restaurant offers MoreTables credits and whether it has set a custom reward score.
     */
    #[Response(200, type: 'array{rewards: array{restaurant_id: int, offers_credits: bool, has_custom_score: bool, reservation_reward_points: int|null, default_reward_points: int}}')]
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'rewards' => [
                'restaurant_id' => $restaurant->id,
                'offers_credits' => $restaurant->offersMoretablesCredits(),
                'has_custom_score' => $restaurant->hasCustomReservationRewardPoints(),
                'reservation_reward_points' => $restaurant->reservation_reward_points,
                'default_reward_points' => RestaurantRewardRuleService::DEFAULT_POINTS,
            ],
        ]);
    }
}
