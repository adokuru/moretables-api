<?php

namespace App\Http\Controllers\Api\V1;

use App\BillingPlanSlug;
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

        $restaurant->load(['activeBillingSubscription.plan', 'organization.activeBillingSubscription.plan']);
        $subscription = $restaurant->effectiveBillingSubscription();
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
                    'display_amount' => $plan ? number_format($plan->amount / 100, 2) : null,
                    'currency' => $plan?->currency ?? 'NGN',
                    'interval' => $plan?->interval,
                    'is_subscribed' => $subscription !== null,
                ],
                'rewards_enabled' => $restaurant->rewards_enabled,
                'reservation_reward_points' => $restaurant->reservation_reward_points,
            ],
        ]);
    }

    public function update(UpdateRestaurantSettingsRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        // Guest Loyalty Program is Core/Premium-only (docs/PLAN_PERMISSIONS.md) — a
        // Foundation restaurant can't opt in, regardless of what it submits here.
        if (($validated['rewards_enabled'] ?? false) && ! $restaurant->hasPlanAtLeast(BillingPlanSlug::Core)) {
            $validated['rewards_enabled'] = false;
        }

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
                'rewards_enabled' => $restaurant->rewards_enabled,
                'reservation_reward_points' => $restaurant->reservation_reward_points,
            ],
        ]);
    }
}
