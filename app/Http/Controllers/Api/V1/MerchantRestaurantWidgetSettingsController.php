<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateRestaurantWidgetSettingsRequest;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Widget Settings', weight: 37)]
class MerchantRestaurantWidgetSettingsController extends Controller
{
    /**
     * Settings for the embeddable reservation widget.
     */
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json(['data' => $restaurant->widgetSettingsWithDefaults()]);
    }

    public function update(UpdateRestaurantWidgetSettingsRequest $request, Restaurant $restaurant): JsonResponse
    {
        $settings = array_merge(
            $restaurant->widgetSettingsWithDefaults(),
            $request->validated(),
        );

        $restaurant->fill(['widget_settings' => $settings])->save();

        return response()->json([
            'message' => 'Widget settings saved.',
            'data' => $restaurant->refresh()->widgetSettingsWithDefaults(),
        ]);
    }
}
