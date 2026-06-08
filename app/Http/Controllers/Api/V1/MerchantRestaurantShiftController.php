<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantShiftRequest;
use App\Http\Requests\Merchant\UpdateRestaurantShiftRequest;
use App\Http\Resources\RestaurantShiftResource;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\Services\RestaurantShiftService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Merchant Shifts', weight: 37)]
class MerchantRestaurantShiftController extends Controller
{
    public function __construct(protected RestaurantShiftService $shiftService) {}

    public function index(Restaurant $restaurant): AnonymousResourceCollection
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $shifts = $restaurant->shifts()
            ->with(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals'])
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->orderBy('starts_at')
            ->get();

        return RestaurantShiftResource::collection($shifts);
    }

    /**
     * Create a weekly shift with optional table availability, turn times, turn controls, and flow settings.
     */
    public function store(StoreRestaurantShiftRequest $request, Restaurant $restaurant): JsonResponse
    {
        $shift = $this->shiftService->create($restaurant, $request->validated());

        return RestaurantShiftResource::make($shift->load(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Restaurant $restaurant, RestaurantShift $shift): RestaurantShiftResource
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $shift);

        return RestaurantShiftResource::make($shift->load(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals']));
    }

    /**
     * Update a weekly shift and its nested availability settings.
     */
    public function update(UpdateRestaurantShiftRequest $request, Restaurant $restaurant, RestaurantShift $shift): RestaurantShiftResource
    {
        $this->ensureBelongsToRestaurant($restaurant, $shift);

        $shift = $this->shiftService->update($restaurant, $shift, $request->validated());

        return RestaurantShiftResource::make($shift->load(['turnTimes', 'tableAvailability', 'turnControls', 'flowIntervals']));
    }

    public function destroy(Restaurant $restaurant, RestaurantShift $shift): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $shift);

        $this->shiftService->delete($shift);

        return response()->json([
            'message' => 'Shift deleted successfully.',
        ]);
    }

    private function ensureBelongsToRestaurant(Restaurant $restaurant, RestaurantShift $shift): void
    {
        abort_unless((int) $shift->restaurant_id === (int) $restaurant->id, 404);
    }
}
