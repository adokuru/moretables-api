<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantSpecialDayRequest;
use App\Http\Requests\Merchant\UpdateRestaurantSpecialDayRequest;
use App\Http\Resources\RestaurantSpecialDayResource;
use App\Models\Restaurant;
use App\Models\RestaurantSpecialDay;
use App\Services\RestaurantSpecialDayService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Merchant Special Days', weight: 36)]
class MerchantRestaurantSpecialDayController extends Controller
{
    public function __construct(protected RestaurantSpecialDayService $specialDayService) {}

    public function index(Restaurant $restaurant): AnonymousResourceCollection
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $specialDays = $restaurant->specialDays()
            ->with('shifts')
            ->orderBy('date')
            ->get();

        return RestaurantSpecialDayResource::collection($specialDays);
    }

    public function store(StoreRestaurantSpecialDayRequest $request, Restaurant $restaurant): JsonResponse
    {
        $specialDay = $this->specialDayService->create($restaurant, $request->validated());

        return RestaurantSpecialDayResource::make($specialDay->load('shifts'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Restaurant $restaurant, RestaurantSpecialDay $specialDay): RestaurantSpecialDayResource
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $specialDay);

        return RestaurantSpecialDayResource::make($specialDay->load('shifts'));
    }

    public function update(UpdateRestaurantSpecialDayRequest $request, Restaurant $restaurant, RestaurantSpecialDay $specialDay): RestaurantSpecialDayResource
    {
        $this->ensureBelongsToRestaurant($restaurant, $specialDay);

        $specialDay = $this->specialDayService->update($restaurant, $specialDay, $request->validated());

        return RestaurantSpecialDayResource::make($specialDay->load('shifts'));
    }

    public function destroy(Restaurant $restaurant, RestaurantSpecialDay $specialDay): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        $this->ensureBelongsToRestaurant($restaurant, $specialDay);

        $specialDay->delete();

        return response()->json([
            'message' => 'Special day deleted successfully.',
        ]);
    }

    private function ensureBelongsToRestaurant(Restaurant $restaurant, RestaurantSpecialDay $specialDay): void
    {
        abort_unless((int) $specialDay->restaurant_id === (int) $restaurant->id, 404);
    }
}
