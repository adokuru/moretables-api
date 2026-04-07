<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Organizations', weight: 50)]
class AdminOrganizationController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[Response(200, type: 'array{data: list<OrganizationResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->withCount('restaurants')
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($organizationQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $organizationQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%')
                        ->orWhere('primary_contact_email', 'like', '%'.$search.'%')
                        ->orWhere('business_email', 'like', '%'.$search.'%');
                }),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => OrganizationResource::collection($organizations->getCollection())->resolve($request),
            'links' => [
                'first' => $organizations->url(1),
                'last' => $organizations->url($organizations->lastPage()),
                'prev' => $organizations->previousPageUrl(),
                'next' => $organizations->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'from' => $organizations->firstItem(),
                'last_page' => $organizations->lastPage(),
                'path' => $organizations->path(),
                'per_page' => $organizations->perPage(),
                'to' => $organizations->lastItem(),
                'total' => $organizations->total(),
            ],
        ]);
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

    public function destroy(Organization $organization): JsonResponse
    {
        $this->authorize('delete', $organization);

        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully.',
        ]);
    }
}
