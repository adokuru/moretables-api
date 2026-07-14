<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\ListRestaurantServersRequest;
use App\Http\Requests\Merchant\StoreRestaurantServerRequest;
use App\Http\Resources\RestaurantServerResource;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Front of House / Servers', weight: 38)]
class MerchantRestaurantServerController extends Controller
{
    public function index(ListRestaurantServersRequest $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'servers' => RestaurantServerResource::collection(
                $restaurant->servers()
                    ->with(['tableAssignments' => fn ($query) => $query
                        ->where('service_starts_at', $validated['service_starts_at'])
                        ->where('service_ends_at', $validated['service_ends_at'])
                    ])
                    ->orderBy('name')
                    ->get()
            ),
        ]);
    }

    public function store(StoreRestaurantServerRequest $request, Restaurant $restaurant): JsonResponse
    {
        $server = $restaurant->servers()->create($request->validated());

        return response()->json([
            'message' => 'Server added successfully.',
            'server' => RestaurantServerResource::make($server),
        ], 201);
    }
}
