<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateRestaurantBookingPolicyRequest;
use App\Http\Resources\RestaurantBookingPolicyResource;
use App\Models\Restaurant;
use App\Models\RestaurantPolicy;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Merchant Booking Policies', weight: 36)]
class MerchantRestaurantBookingPolicyController extends Controller
{
    public function show(Request $request, Restaurant $restaurant): RestaurantBookingPolicyResource
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $policy = $this->policyFor($restaurant);

        return RestaurantBookingPolicyResource::make($policy);
    }

    public function update(UpdateRestaurantBookingPolicyRequest $request, Restaurant $restaurant): RestaurantBookingPolicyResource
    {
        $policy = $this->policyFor($restaurant);
        $policy->fill($request->validated())->save();

        return RestaurantBookingPolicyResource::make($policy->refresh());
    }

    private function policyFor(Restaurant $restaurant): RestaurantPolicy
    {
        return RestaurantPolicy::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'booking_details_locale' => 'en',
            ],
        );
    }
}
