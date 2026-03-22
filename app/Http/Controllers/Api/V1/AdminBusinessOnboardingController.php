<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminBusinessOnboardingRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\RestaurantDetailResource;
use App\Http\Resources\UserResource;
use App\Services\AdminBusinessOnboardingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin Organizations', weight: 51)]
class AdminBusinessOnboardingController extends Controller
{
    public function __construct(protected AdminBusinessOnboardingService $adminBusinessOnboardingService) {}

    public function store(StoreAdminBusinessOnboardingRequest $request): JsonResponse
    {
        $result = $this->adminBusinessOnboardingService->onboard(
            payload: $request->validated(),
            admin: $request->user(),
        );

        return response()->json([
            'message' => 'Business onboarding completed successfully.',
            'organization' => OrganizationResource::make($result['organization']),
            'owner' => UserResource::make($result['owner']),
            'restaurants' => RestaurantDetailResource::collection($result['restaurants']),
        ], 201);
    }
}
