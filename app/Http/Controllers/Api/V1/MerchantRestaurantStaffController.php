<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantStaffRequest;
use App\Http\Requests\Merchant\UpdateRestaurantStaffRequest;
use App\Http\Resources\RestaurantStaffAssignmentResource;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantStaffManagementService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Staff', weight: 31)]
class MerchantRestaurantStaffController extends Controller
{
    public function __construct(protected RestaurantStaffManagementService $restaurantStaffManagementService) {}

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);

        return response()->json([
            'staff' => RestaurantStaffAssignmentResource::collection(
                $this->restaurantStaffManagementService->listForRestaurant($restaurant)
            ),
        ]);
    }

    public function store(StoreRestaurantStaffRequest $request, Restaurant $restaurant): JsonResponse
    {
        $assignment = $this->restaurantStaffManagementService->invite(
            restaurant: $restaurant,
            payload: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Staff member invited successfully.',
            'staff_member' => RestaurantStaffAssignmentResource::make($assignment),
        ], 201);
    }

    public function update(UpdateRestaurantStaffRequest $request, Restaurant $restaurant, User $user): JsonResponse
    {
        $assignment = $this->restaurantStaffManagementService->update(
            restaurant: $restaurant,
            staffMember: $user,
            payload: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Staff member updated successfully.',
            'staff_member' => RestaurantStaffAssignmentResource::make($assignment),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant, User $user): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);

        $this->restaurantStaffManagementService->remove($restaurant, $user);

        return response()->json([
            'message' => 'Staff member removed from the restaurant successfully.',
        ]);
    }
}
