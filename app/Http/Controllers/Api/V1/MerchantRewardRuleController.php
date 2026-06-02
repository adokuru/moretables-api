<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantRewardRuleRequest;
use App\Http\Requests\Merchant\UpdateRestaurantRewardRuleRequest;
use App\Http\Resources\RestaurantRewardRuleResource;
use App\Models\Restaurant;
use App\Models\RestaurantRewardRule;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Merchant Reward Rules', weight: 37)]
class MerchantRewardRuleController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $rules = $restaurant->rewardRules()
            ->latest()
            ->get();

        return RestaurantRewardRuleResource::collection($rules);
    }

    /**
     * Create a custom reward-point rule. Omit `times` to award the points all day on the selected days.
     */
    public function store(StoreRestaurantRewardRuleRequest $request, Restaurant $restaurant): JsonResponse
    {
        $rule = $restaurant->rewardRules()->create($this->normalize($request->validated()));

        return RestaurantRewardRuleResource::make($rule)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Restaurant $restaurant, RestaurantRewardRule $rewardRule): RestaurantRewardRuleResource
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $rewardRule);

        return RestaurantRewardRuleResource::make($rewardRule);
    }

    public function update(UpdateRestaurantRewardRuleRequest $request, Restaurant $restaurant, RestaurantRewardRule $rewardRule): RestaurantRewardRuleResource
    {
        $this->ensureBelongsToRestaurant($restaurant, $rewardRule);

        $rewardRule->fill($this->normalize($request->validated()))->save();

        return RestaurantRewardRuleResource::make($rewardRule->refresh());
    }

    public function destroy(Request $request, Restaurant $restaurant, RestaurantRewardRule $rewardRule): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $rewardRule);

        $rewardRule->delete();

        return response()->json([
            'message' => 'Reward rule deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        if (array_key_exists('times', $validated) && empty($validated['times'])) {
            $validated['times'] = null;
        }

        return $validated;
    }

    private function ensureBelongsToRestaurant(Restaurant $restaurant, RestaurantRewardRule $rewardRule): void
    {
        abort_unless((int) $rewardRule->restaurant_id === (int) $restaurant->id, 404);
    }
}
