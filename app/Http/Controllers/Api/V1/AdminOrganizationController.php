<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class AdminOrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()->withCount('restaurants')->paginate(20);

        return response()->json(OrganizationResource::collection($organizations));
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $validated = $request->validated();
        $organization = Organization::query()->create([
            ...$validated,
            'slug' => $validated['slug'] ?? str($validated['name'])->slug()->toString(),
        ]);

        return response()->json([
            'message' => 'Organization created successfully.',
            'organization' => OrganizationResource::make($organization),
        ], 201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return OrganizationResource::make($organization->loadCount('restaurants'));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $validated = $request->validated();
        if (array_key_exists('name', $validated) && empty($validated['slug'])) {
            $validated['slug'] = str($validated['name'])->slug()->toString();
        }

        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated successfully.',
            'organization' => OrganizationResource::make($organization->refresh()->loadCount('restaurants')),
        ]);
    }
}
