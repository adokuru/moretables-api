<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\MoretableLineupIndexRequest;
use App\Http\Resources\MoretableLineupResource;
use App\Models\MoretableLineup;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Public Moretable Lineup', weight: 1)]
class PublicMoretableLineupController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[QueryParameter('latitude', type: 'number', required: false, example: 6.4281)]
    #[QueryParameter('longitude', type: 'number', required: false, example: 3.4219)]
    #[QueryParameter('radius_km', type: 'number', required: false, default: 25, example: 25)]
    #[Response(200, type: 'array{data: list<MoretableLineupResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(MoretableLineupIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $hasCoordinates = $request->filled('latitude') && $request->filled('longitude');

        $lineups = MoretableLineup::query()
            ->published()
            ->with(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])
            ->when(
                $hasCoordinates,
                fn (Builder $query) => $this->applyNearbyFilter(
                    $query,
                    (float) $validated['latitude'],
                    (float) $validated['longitude'],
                    (float) ($validated['radius_km'] ?? 25),
                ),
                fn (Builder $query) => $query->orderByDesc('is_featured')->latest('published_at'),
            )
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => MoretableLineupResource::collection($lineups->getCollection())->resolve($request),
            'links' => [
                'first' => $lineups->url(1),
                'last' => $lineups->url($lineups->lastPage()),
                'prev' => $lineups->previousPageUrl(),
                'next' => $lineups->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $lineups->currentPage(),
                'from' => $lineups->firstItem(),
                'last_page' => $lineups->lastPage(),
                'path' => $lineups->path(),
                'per_page' => $lineups->perPage(),
                'to' => $lineups->lastItem(),
                'total' => $lineups->total(),
            ],
        ]);
    }

    public function show(Request $request, string $slug): MoretableLineupResource
    {
        $lineup = MoretableLineup::query()
            ->published()
            ->with(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])
            ->where('slug', $slug)
            ->firstOrFail();

        return MoretableLineupResource::make($lineup);
    }

    /**
     * Limit lineups to those whose featured restaurant sits within the radius,
     * exposing a `distance_km` column and ordering nearest first.
     *
     * @param  Builder<MoretableLineup>  $query
     * @return Builder<MoretableLineup>
     */
    protected function applyNearbyFilter(Builder $query, float $latitude, float $longitude, float $radiusKm): Builder
    {
        $bounds = $this->coordinateBounds($latitude, $longitude, $radiusKm);

        $haversine = '(6371 * acos(cos(radians(?)) * cos(radians(restaurants.latitude)) * cos(radians(restaurants.longitude) - radians(?)) + sin(radians(?)) * sin(radians(restaurants.latitude))))';

        return $query
            ->whereHas('restaurant', function ($restaurantQuery) use ($bounds): void {
                $restaurantQuery
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$bounds['min_latitude'], $bounds['max_latitude']])
                    ->whereBetween('longitude', [$bounds['min_longitude'], $bounds['max_longitude']]);
            })
            ->addSelect([
                'distance_km' => Restaurant::query()
                    ->selectRaw($haversine, [$latitude, $longitude, $latitude])
                    ->whereColumn('restaurants.id', 'moretable_lineups.restaurant_id'),
            ])
            ->orderBy('distance_km');
    }

    /**
     * @return array{min_latitude: float, max_latitude: float, min_longitude: float, max_longitude: float}
     */
    protected function coordinateBounds(float $latitude, float $longitude, float $radiusKm): array
    {
        $latitudeDelta = $radiusKm / 111.045;
        $longitudeFactor = max(abs(cos(deg2rad($latitude))), 0.01);
        $longitudeDelta = min($radiusKm / (111.045 * $longitudeFactor), 180.0);

        return [
            'min_latitude' => $latitude - $latitudeDelta,
            'max_latitude' => $latitude + $latitudeDelta,
            'min_longitude' => $longitude - $longitudeDelta,
            'max_longitude' => $longitude + $longitudeDelta,
        ];
    }
}
