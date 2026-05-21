<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RestaurantDiscoveryRequest;
use App\Http\Resources\RestaurantListResource;
use App\Models\Restaurant;
use App\Services\AvailabilityService;
use App\Services\RestaurantDiscoveryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

#[Group('Public Restaurants', weight: 2)]
class PublicRestaurantDiscoveryController extends Controller
{
    public function __construct(
        protected RestaurantDiscoveryService $restaurantDiscoveryService,
        protected AvailabilityService $availabilityService,
    ) {}

    /**
     * Return the homepage discovery rail sections for mobile and web.
     */
    public function index(RestaurantDiscoveryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? 10);
        $sections = [];
        $user = $this->authenticatedUserFromToken($request);

        foreach ($this->restaurantDiscoveryService->listSections($validated, $limit, $user?->id) as $section => $restaurants) {
            $sections[$section] = [
                'label' => $this->restaurantDiscoveryService->sectionLabel($section),
                'restaurants' => RestaurantListResource::collection($this->withReservationTimes($restaurants, $validated))->resolve($request),
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
     * Supported sections: top_booked, top_viewed, top_saved, highly_rated, new_on_moretables, featured, timeofday, moretable_lineup.
     */
    public function show(RestaurantDiscoveryRequest $request, string $section): JsonResponse
    {
        abort_unless($this->restaurantDiscoveryService->supportsSection($section), 404);
        $user = $this->authenticatedUserFromToken($request);

        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 15);

        $paginator = $this->restaurantDiscoveryService
            ->paginateSection($section, $validated, $perPage, $user?->id);

        return response()->json([
            'section' => $this->restaurantDiscoveryService->normalizeSection($section),
            'label' => $this->restaurantDiscoveryService->sectionLabel($section),
            'data' => RestaurantListResource::collection($this->withReservationTimes($paginator->getCollection(), $validated))->resolve($request),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * @param  Collection<int, Restaurant>  $restaurants
     * @param  array<string, mixed>  $validated
     * @return Collection<int, Restaurant>
     */
    protected function withReservationTimes(Collection $restaurants, array $validated): Collection
    {
        $date = (string) ($validated['date'] ?? now()->toDateString());
        $partySize = (int) ($validated['party_size'] ?? 2);
        $timezone = isset($validated['timezone']) ? (string) $validated['timezone'] : null;
        $limit = (int) ($validated['reservation_times_limit'] ?? 5);

        return $restaurants->each(function (Restaurant $restaurant) use ($date, $partySize, $timezone, $limit): void {
            $restaurant->setAttribute('reservation_times', collect($this->availabilityService->listAvailableSlots(
                restaurant: $restaurant,
                date: $date,
                partySize: $partySize,
                requesterTimezone: $timezone,
            ))
                ->take($limit)
                ->values()
                ->all());
        });
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
