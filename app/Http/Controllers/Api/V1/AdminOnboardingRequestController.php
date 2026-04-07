<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOnboardingRequestRequest;
use App\Http\Requests\Admin\UpdateOnboardingRequestRequest;
use App\Http\Resources\OnboardingRequestResource;
use App\Models\OnboardingRequest;
use App\OnboardingRequestStatus;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Onboarding Requests', weight: 55)]
class AdminOnboardingRequestController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[Response(200, type: 'array{data: list<OnboardingRequestResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $onboardingRequests = OnboardingRequest::query()
            ->with('reviewedBy.roles')
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($onboardingQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $onboardingQuery
                        ->where('restaurant_name', 'like', '%'.$search.'%')
                        ->orWhere('owner_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                }),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => OnboardingRequestResource::collection($onboardingRequests->getCollection())->resolve($request),
            'links' => [
                'first' => $onboardingRequests->url(1),
                'last' => $onboardingRequests->url($onboardingRequests->lastPage()),
                'prev' => $onboardingRequests->previousPageUrl(),
                'next' => $onboardingRequests->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $onboardingRequests->currentPage(),
                'from' => $onboardingRequests->firstItem(),
                'last_page' => $onboardingRequests->lastPage(),
                'path' => $onboardingRequests->path(),
                'per_page' => $onboardingRequests->perPage(),
                'to' => $onboardingRequests->lastItem(),
                'total' => $onboardingRequests->total(),
            ],
        ]);
    }

    public function store(StoreOnboardingRequestRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $onboardingRequest = OnboardingRequest::query()->create($request->validated())->refresh();

        return response()->json([
            'message' => 'Onboarding request created successfully.',
            'onboarding_request' => OnboardingRequestResource::make($onboardingRequest),
        ], 201);
    }

    public function show(Request $request, OnboardingRequest $onboardingRequest): OnboardingRequestResource
    {
        $this->ensureAdminAccess($request);

        return OnboardingRequestResource::make($onboardingRequest->load('reviewedBy.roles'));
    }

    public function update(UpdateOnboardingRequestRequest $request, OnboardingRequest $onboardingRequest): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();

        if (array_key_exists('status', $validated)) {
            $status = OnboardingRequestStatus::from($validated['status']);

            if ($status === OnboardingRequestStatus::Pending) {
                $validated['reviewed_at'] = null;
                $validated['reviewed_by'] = null;
            } else {
                $validated['reviewed_at'] = now();
                $validated['reviewed_by'] = $request->user()->id;
            }
        }

        $onboardingRequest->update($validated);

        return response()->json([
            'message' => 'Onboarding request updated successfully.',
            'onboarding_request' => OnboardingRequestResource::make($onboardingRequest->refresh()->load('reviewedBy.roles')),
        ]);
    }

    public function destroy(Request $request, OnboardingRequest $onboardingRequest): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $onboardingRequest->delete();

        return response()->json([
            'message' => 'Onboarding request deleted successfully.',
        ]);
    }
}
