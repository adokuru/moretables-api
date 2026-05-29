<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOnboardingRequestRequest;
use App\Http\Resources\OnboardingRequestResource;
use App\Models\OnboardingRequest;
use App\Services\OnboardingRequestNotificationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Onboarding Requests', weight: 5)]
class OnboardingRequestController extends Controller
{
    /**
     * Submit an onboarding request and notify MoreTables admins by email and database notification.
     */
    public function store(StoreOnboardingRequestRequest $request, OnboardingRequestNotificationService $notifications): JsonResponse
    {
        $validated = $request->validated();

        $validated['owner_name'] = trim(implode(' ', array_filter([
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null,
        ])));

        $onboardingRequest = OnboardingRequest::query()->create($validated);

        $notifications->notifyAdmins($onboardingRequest);

        return response()->json([
            'message' => 'Onboarding request submitted successfully.',
            'onboarding_request' => OnboardingRequestResource::make($onboardingRequest),
        ], 201);
    }
}
