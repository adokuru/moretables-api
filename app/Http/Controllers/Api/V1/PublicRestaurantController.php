<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RestaurantAvailabilityRequest;
use App\Http\Requests\Public\RestaurantIndexRequest;
use App\Http\Resources\RestaurantDetailResource;
use App\Http\Resources\RestaurantListResource;
use App\Models\Restaurant;
use App\RestaurantStatus;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;

class PublicRestaurantController extends Controller
{
    public function __construct(protected AvailabilityService $availabilityService) {}

    public function index(RestaurantIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $hasCoordinates = $request->filled('latitude') && $request->filled('longitude');

        $restaurants = Restaurant::query()
            ->with(['cuisines', 'media'])
            ->where('status', RestaurantStatus::Active->value)
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
                $radiusKm = (float) ($validated['radius_km'] ?? 25);
                $bounds = $this->coordinateBounds(
                    latitude: (float) $validated['latitude'],
                    longitude: (float) $validated['longitude'],
                    radiusKm: $radiusKm,
                );

                $query->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$bounds['min_latitude'], $bounds['max_latitude']])
                    ->whereBetween('longitude', [$bounds['min_longitude'], $bounds['max_longitude']])
                    ->orderByRaw(
                        'ABS(latitude - ?) + ABS(longitude - ?) asc',
                        [(float) $validated['latitude'], (float) $validated['longitude']],
                    );
            })
            ->paginate($validated['per_page'] ?? 15);

        return response()->json(RestaurantListResource::collection($restaurants));
    }

    public function show(Restaurant $restaurant): RestaurantDetailResource
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        return RestaurantDetailResource::make($restaurant->load([
            'cuisines',
            'media',
            'hours',
            'policy',
            'menuItems.media',
            'diningAreas.tables',
        ]));
    }

    public function availability(RestaurantAvailabilityRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $restaurant->loadMissing(['hours', 'policy']);

        $slots = $this->availabilityService->listAvailableSlots(
            restaurant: $restaurant,
            date: $request->string('date')->toString(),
            partySize: (int) $request->integer('party_size'),
        );

        if ($request->filled('time')) {
            $requestedTime = $request->string('time')->toString();
            $slots = array_values(array_filter($slots, function (array $slot) use ($requestedTime): bool {
                return str_contains($slot['local_starts_at'], $requestedTime);
            }));
        }

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'timezone' => $restaurant->timezone,
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
}
