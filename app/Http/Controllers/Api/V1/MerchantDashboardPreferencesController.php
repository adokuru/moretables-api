<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateDashboardPreferencesRequest;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Display preferences for the front-of-house dashboard (Nav/Sidebar → Settings →
 * Preferences), as distinct from the business-facing restaurant settings served
 * by MerchantRestaurantSettingsController. One boolean today
 * (display_recommended_table_assignment); more dashboard toggles can be added
 * to the same resource later.
 */
#[Group('Merchant Restaurant Settings', weight: 31)]
class MerchantDashboardPreferencesController extends Controller
{
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'preferences' => [
                'display_recommended_table_assignment' => $restaurant->display_recommended_table_assignment,
            ],
        ]);
    }

    public function update(UpdateDashboardPreferencesRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $restaurant->fill($request->validated())->save();

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'preferences' => [
                'display_recommended_table_assignment' => $restaurant->display_recommended_table_assignment,
            ],
        ]);
    }
}
