<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateRestaurantSettingsRequest;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Restaurant Settings', weight: 31)]
class MerchantRestaurantSettingsController extends Controller
{
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $restaurant->load('activeBillingSubscription.plan');
        $subscription = $restaurant->activeBillingSubscription;
        $plan = $subscription?->plan;

        return response()->json([
            'settings' => [
                'name' => $restaurant->name,
                'website' => $restaurant->website,
                'country' => $restaurant->country,
                'state' => $restaurant->state,
                'city' => $restaurant->city,
                'address_line_1' => $restaurant->address_line_1,
                'plan' => [
                    'name' => $plan?->name,
                    'slug' => $plan?->slug?->value ?? $plan?->slug,
                    'amount' => $plan?->amount,
                    'currency' => $plan?->currency ?? 'NGN',
                    'interval' => $plan?->interval,
                    'is_subscribed' => $subscription !== null,
                ],
            ],
        ]);
    }

    public function update(UpdateRestaurantSettingsRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        $restaurant->fill($validated)->save();

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => [
                'name' => $restaurant->name,
                'website' => $restaurant->website,
                'country' => $restaurant->country,
                'state' => $restaurant->state,
                'city' => $restaurant->city,
                'address_line_1' => $restaurant->address_line_1,
            ],
        ]);
    }
}
