<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Events\TableStatusUpdated;
use App\Http\Requests\Merchant\StoreRestaurantTableRequest;
use App\Http\Requests\Merchant\UpdateRestaurantTableRequest;
use App\Http\Requests\Merchant\UpdateTableStatusRequest;
use App\Http\Resources\RestaurantTableResource;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class MerchantTableController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);

        return response()->json(RestaurantTableResource::collection(
            $restaurant->tables()->orderBy('sort_order')->get()
        ));
    }

    public function store(StoreRestaurantTableRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $validated = $request->validated();
        $validated['restaurant_id'] = $restaurant->id;

        $table = $restaurant->tables()->create($validated);

        return response()->json([
            'message' => 'Table created successfully.',
            'table' => RestaurantTableResource::make($table),
        ], 201);
    }

    public function update(UpdateRestaurantTableRequest $request, Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->update($request->validated());

        return response()->json([
            'message' => 'Table updated successfully.',
            'table' => RestaurantTableResource::make($table->refresh()),
        ]);
    }

    public function updateStatus(UpdateTableStatusRequest $request, Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->update(['status' => $request->validated('status')]);
        event(new TableStatusUpdated($table->refresh(), 'status_changed'));

        return response()->json([
            'message' => 'Table status updated successfully.',
            'table' => RestaurantTableResource::make($table),
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }
}
