<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RestaurantAvailabilityRequest;
use App\Http\Requests\Public\RestaurantIndexRequest;
use App\Http\Requests\Public\RestaurantSearchRequest;
use App\Http\Resources\PublicRandomReviewResource;
use App\Http\Resources\RestaurantDetailResource;
use App\Http\Resources\RestaurantListResource;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\SavedRestaurant;
use App\Services\AvailabilityService;
use App\Services\PerformanceCacheService;
use App\Services\RestaurantReviewSummaryService;
use App\Services\RestaurantSearchService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Public Restaurants', weight: 0)]
class PublicRestaurantController extends Controller
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected RestaurantSearchService $restaurantSearchService,
        protected RestaurantReviewSummaryService $reviewSummary,
        protected PerformanceCacheService $performanceCache,
    ) {}

    /**
     * Typeahead search for locations, restaurants, and cuisines.
     *
     * Only includes restaurants that are publicly listed: status active and an active or trialing
     * merchant subscription whose current billing period has not expired.
     */
    public function search(RestaurantSearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->authenticatedUserFromToken($request);
        $query = (string) ($validated['q'] ?? '');
        $limit = (int) ($validated['limit'] ?? 5);

        $payload = $this->performanceCache->flexible(
            $this->performanceCache->versionedKey('public-fragments', 'typeahead', hash('sha256', "{$query}:{$limit}")),
            'public_fragments',
            function () use ($limit, $query): array {
                $results = $this->restaurantSearchService->search(
                    query: $query,
                    limit: $limit,
                );

                return [
                    'query' => $query,
                    'results' => [
                        'locations' => $results['locations']->values()->all(),
                        'restaurants' => RestaurantListResource::collection($results['restaurants'])->resolve(),
                        'cuisines' => $results['cuisines']->values()->all(),
                    ],
                ];
            },
        );

        $payload['results']['restaurants'] = $this->withSavedRestaurants($payload['results']['restaurants'], $user?->id);

        return response()->json($payload);
    }

    /**
     * Get public reviews ordered by highest rating first.
     *
     * Returns reviews from publicly listed restaurants (active status with active or trialing
     * merchant subscription) ordered by highest rating first. Within the same rating, order is random.
     */
    #[QueryParameter('limit', type: 'integer', default: 10, example: 6)]
    #[Response(200, type: 'array{data: list<PublicRandomReviewResource>}')]
    public function randomReviews(Request $request): JsonResponse
    {
        $limit = max(1, min($request->integer('limit', 10), 50));

        $payload = $this->performanceCache->flexible(
            $this->performanceCache->versionedKey('random-reviews', (string) $limit),
            'public_fragments',
            function () use ($limit, $request): array {
                $reviews = RestaurantReview::query()
                    ->with([
                        'user:id,name,first_name,last_name',
                        'restaurant:id,name,status',
                    ])
                    ->whereHas('restaurant', fn ($query) => $query->publiclyListed())
                    ->orderByDesc('rating')
                    ->inRandomOrder()
                    ->limit($limit)
                    ->get();

                return [
                    'data' => PublicRandomReviewResource::collection($reviews)->resolve($request),
                ];
            },
        );

        return response()->json($payload);
    }

    /**
     * Paginated public restaurant listing.
     *
     * Only restaurants that are publicly listed are returned: status active and an active or trialing
     * merchant subscription whose current billing period has not expired.
     */
    #[Response(200, description: 'Paginated list of publicly listed restaurants (active with active or trialing merchant subscription).')]
    public function index(RestaurantIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $hasCoordinates = $request->filled('latitude') && $request->filled('longitude');
        $user = $this->authenticatedUserFromToken($request);

        $payload = $this->performanceCache->flexible(
            $this->performanceCache->versionedKey(
                'public-fragments',
                'restaurants',
                (string) $request->integer('page', 1),
                hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR)),
            ),
            'public_fragments',
            function () use ($hasCoordinates, $request, $validated): array {
                $restaurants = Restaurant::query()
                    ->with([
                        'cuisines',
                        'media',
                        'hours' => fn ($query) => $query->orderBy('day_of_week'),
                        'availabilitySchedules' => fn ($query) => $query->orderBy('day_of_week')->orderBy('opens_at'),
                    ])
                    ->withCount('reviews as reviews_count')
                    ->withAvg('reviews as average_rating', 'rating')
                    ->publiclyListed()
                    ->when($request->filled('q'), function ($query) use ($validated) {
                        $query->where(function ($subQuery) use ($validated): void {
                            $subQuery->where('name', 'like', '%'.$validated['q'].'%')
                                ->orWhere('description', 'like', '%'.$validated['q'].'%');
                        });
                    })
                    ->when($request->filled('city'), function ($query) use ($validated) {
                        $query->where('city', $validated['city']);
                    })
                    ->when($request->filled('cuisine'), function ($query) use ($validated) {
                        $query->whereHas('cuisines', function ($subQuery) use ($validated): void {
                            $subQuery->where('name', 'like', '%'.$validated['cuisine'].'%');
                        });
                    })
                    ->when($hasCoordinates, function ($query) use ($validated): void {
                        $lat = (float) $validated['latitude'];
                        $lng = (float) $validated['longitude'];
                        $radiusKm = (float) ($validated['radius_km'] ?? 25);
                        $bounds = $this->coordinateBounds(
                            latitude: $lat,
                            longitude: $lng,
                            radiusKm: $radiusKm,
                        );

                        $query->addSelect(DB::raw(
                            "(6371 * acos(cos(radians({$lat})) * cos(radians(latitude)) * cos(radians(longitude) - radians({$lng})) + sin(radians({$lat})) * sin(radians(latitude)))) AS distance_km"
                        ))
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude')
                            ->whereBetween('latitude', [$bounds['min_latitude'], $bounds['max_latitude']])
                            ->whereBetween('longitude', [$bounds['min_longitude'], $bounds['max_longitude']])
                            ->orderByRaw(
                                'ABS(latitude - ?) + ABS(longitude - ?) asc',
                                [$lat, $lng],
                            );
                    })
                    ->paginate($validated['per_page'] ?? 15);

                return RestaurantListResource::collection($restaurants)->resolve($request);
            },
        );

        $payload = $this->withSavedRestaurants($payload, $user?->id);

        return response()->json($payload);
    }

    /**
     * Public restaurant detail.
     *
     * Returns 404 when the restaurant is not publicly listed (inactive or without an active or
     * trialing merchant subscription whose current billing period has not expired).
     */
    #[Response(200, type: RestaurantDetailResource::class)]
    #[Response(404, description: 'Restaurant is inactive or does not have an active or trialing merchant subscription.')]
    public function show(Request $request, Restaurant $restaurant): RestaurantDetailResource
    {
        abort_unless($restaurant->isPubliclyListed(), 404);
        $user = $this->authenticatedUserFromToken($request);

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        $restaurant = Restaurant::query()
            ->when($user, function ($query, $user): void {
                $query->withExists([
                    'savedEntries as has_saved' => fn ($subQuery) => $subQuery->where('user_id', $user->id),
                ]);
            })
            ->withCount([
                'reservations as bookings_count',
                'views as views_count',
                'savedEntries as saves_count',
                'listItems as list_adds_count',
                'reviews as reviews_count',
            ])
            ->withAvg('reviews as average_rating', 'rating')
            ->whereKey($restaurant->getKey())
            ->firstOrFail();

        $reviewSummary = $this->reviewSummary->summarize($restaurant->reviews(), $restaurant->id);

        $restaurant->setAttribute('ratings_breakdown', (object) $reviewSummary['ratings_breakdown']);
        $restaurant->setAttribute('category_breakdown', $reviewSummary['category_breakdown']);

        return RestaurantDetailResource::make($restaurant->load([
            'cuisines',
            'media',
            'hours',
            'availabilityPeriods.schedules',
            'availabilitySchedules',
            'policy',
            'cancellationPolicies' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id'),
            'rewardRules' => fn ($query) => $query->active()->orderBy('id'),
            'menuItems.media',
            'diningAreas.tables',
        ]));
    }

    /**
     * Live reservation availability for a publicly listed restaurant.
     *
     * Returns 404 when the restaurant is not publicly listed (inactive or without an active or
     * trialing merchant subscription whose current billing period has not expired).
     *
     * When `time` is provided (H:i), only slots starting at or after that time on the requested date are returned.
     */
    #[QueryParameter('date', type: 'string', required: true, example: '2026-06-09')]
    #[QueryParameter('time', type: 'string', required: false, example: '13:30')]
    #[QueryParameter('party_size', type: 'integer', required: true, example: 2)]
    #[QueryParameter('timezone', type: 'string', required: false)]
    #[Response(429, type: 'array{message: string}')]
    public function availability(RestaurantAvailabilityRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->isPubliclyListed(), 404);

        $restaurant->loadMissing(['hours', 'policy', 'availabilitySchedules']);

        $requesterTimezone = $request->string('timezone')->toString() ?: null;

        $slots = $this->availabilityService->listAvailableSlots(
            restaurant: $restaurant,
            date: $request->string('date')->toString(),
            partySize: (int) $request->integer('party_size'),
            requesterTimezone: $requesterTimezone,
        );

        if ($request->filled('time')) {
            $requestedTime = $request->string('time')->toString();
            $slots = array_values(array_filter($slots, function (array $slot) use ($requestedTime): bool {
                return Carbon::parse($slot['local_starts_at'])->format('H:i') >= $requestedTime;
            }));
        }

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'timezone' => $requesterTimezone ?? $restaurant->timezone,
            'slots' => $slots,
        ]);
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

    /**
     * @param  list<array<string, mixed>>  $restaurants
     * @return list<array<string, mixed>>
     */
    private function withSavedRestaurants(array $restaurants, ?int $userId): array
    {
        if (! $userId || $restaurants === []) {
            return $restaurants;
        }

        $savedRestaurantIds = SavedRestaurant::query()
            ->where('user_id', $userId)
            ->whereIn('restaurant_id', collect($restaurants)->pluck('id'))
            ->pluck('restaurant_id')
            ->flip();

        return collect($restaurants)
            ->map(function (array $restaurant) use ($savedRestaurantIds): array {
                $restaurant['has_saved'] = $savedRestaurantIds->has($restaurant['id']);

                return $restaurant;
            })
            ->all();
    }
}
