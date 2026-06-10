<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CancellationPolicyPartySizeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantCancellationPolicyRequest;
use App\Http\Requests\Merchant\UpdateRestaurantCancellationPolicyRequest;
use App\Http\Resources\RestaurantCancellationPolicyResource;
use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Merchant Cancellation Policies', weight: 36)]
class MerchantRestaurantCancellationPolicyController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $policies = $restaurant->cancellationPolicies()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return RestaurantCancellationPolicyResource::collection($policies);
    }

    /**
     * Create a cancellation or no-show policy with optional date and time windows.
     */
    public function store(StoreRestaurantCancellationPolicyRequest $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $this->normalize($request->validated());

        if (! array_key_exists('sort_order', $validated)) {
            $validated['sort_order'] = (int) $restaurant->cancellationPolicies()->max('sort_order') + 1;
        }

        $policy = $restaurant->cancellationPolicies()->create($validated);

        return RestaurantCancellationPolicyResource::make($policy)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Restaurant $restaurant,
        RestaurantCancellationPolicy $cancellationPolicy,
    ): RestaurantCancellationPolicyResource {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $cancellationPolicy);

        return RestaurantCancellationPolicyResource::make($cancellationPolicy);
    }

    public function update(
        UpdateRestaurantCancellationPolicyRequest $request,
        Restaurant $restaurant,
        RestaurantCancellationPolicy $cancellationPolicy,
    ): RestaurantCancellationPolicyResource {
        $this->ensureBelongsToRestaurant($restaurant, $cancellationPolicy);

        $cancellationPolicy->fill($this->normalize($request->validated()))->save();

        return RestaurantCancellationPolicyResource::make($cancellationPolicy->refresh());
    }

    public function destroy(
        Request $request,
        Restaurant $restaurant,
        RestaurantCancellationPolicy $cancellationPolicy,
    ): JsonResponse {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $cancellationPolicy);

        $cancellationPolicy->delete();

        return response()->json([
            'message' => 'Cancellation policy deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        $scope = $validated['party_size_scope'] ?? null;

        if ($scope !== CancellationPolicyPartySizeScope::Custom->value) {
            $validated['min_party_size'] = null;
            $validated['max_party_size'] = null;
        }

        return $validated;
    }

    private function ensureBelongsToRestaurant(Restaurant $restaurant, RestaurantCancellationPolicy $cancellationPolicy): void
    {
        abort_unless((int) $cancellationPolicy->restaurant_id === (int) $restaurant->id, 404);
    }
}
