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
 * by MerchantRestaurantSettingsController. Four booleans today
 * (display_recommended_table_assignment, display_guest_full_name,
 * show_guest_preferences, show_cleaned_tables); more dashboard toggles can be
 * added to the same resource later.
 */
#[Group('Merchant Restaurant Settings', weight: 31)]
class MerchantDashboardPreferencesController extends Controller
{
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'preferences' => $this->preferences($restaurant),
        ]);
    }

    public function update(UpdateDashboardPreferencesRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $restaurant->fill($request->validated())->save();

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'preferences' => $this->preferences($restaurant),
        ]);
    }

    /**
     * @return array{display_recommended_table_assignment: bool, display_guest_full_name: bool, show_guest_preferences: bool, show_cleaned_tables: bool}
     */
    private function preferences(Restaurant $restaurant): array
    {
        return [
            'display_recommended_table_assignment' => $restaurant->display_recommended_table_assignment,
            'display_guest_full_name' => $restaurant->display_guest_full_name,
            'show_guest_preferences' => $restaurant->show_guest_preferences,
            'show_cleaned_tables' => $restaurant->show_cleaned_tables,
        ];
    }
}
