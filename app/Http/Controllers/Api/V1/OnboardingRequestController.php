<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOnboardingRequestRequest;
use App\Http\Resources\OnboardingRequestResource;
use App\Models\OnboardingRequest;
use Illuminate\Http\JsonResponse;

class OnboardingRequestController extends Controller
{
    public function store(StoreOnboardingRequestRequest $request): JsonResponse
    {
        $onboardingRequest = OnboardingRequest::query()->create($request->validated());

        return response()->json([
            'message' => 'Onboarding request submitted successfully.',
            'onboarding_request' => OnboardingRequestResource::make($onboardingRequest),
        ], 201);
    }
}
