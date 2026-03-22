<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RestaurantDiscoveryRequest;
use App\Http\Resources\RestaurantListResource;
use App\Services\RestaurantDiscoveryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

#[Group('Public Restaurants', weight: 2)]
class PublicRestaurantDiscoveryController extends Controller
{
    public function __construct(protected RestaurantDiscoveryService $restaurantDiscoveryService) {}

    /**
     * Return the homepage discovery rail sections for mobile and web.
     */
    public function index(RestaurantDiscoveryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? 10);
        $sections = [];

        foreach ($this->restaurantDiscoveryService->listSections($validated, $limit) as $section => $restaurants) {
            $sections[$section] = [
                'label' => $this->restaurantDiscoveryService->sectionLabel($section),
                'restaurants' => RestaurantListResource::collection($restaurants)->resolve($request),
            ];
        }

        return response()->json([
            'sections' => $sections,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Return one discovery section with pagination.
     *
     * Supported sections: top_booked, top_viewed, top_saved, highly_rated, new_on_moretables, featured.
     */
    public function show(RestaurantDiscoveryRequest $request, string $section): JsonResponse
    {
        abort_unless($this->restaurantDiscoveryService->supportsSection($section), 404);

        $paginator = $this->restaurantDiscoveryService
            ->paginateSection($section, $request->validated(), (int) ($request->validated()['per_page'] ?? 15));

        return response()->json([
            'section' => $this->restaurantDiscoveryService->normalizeSection($section),
            'label' => $this->restaurantDiscoveryService->sectionLabel($section),
            'data' => RestaurantListResource::collection($paginator->getCollection())->resolve($request),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
