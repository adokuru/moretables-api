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
    public function __construct(protected AvailabilityService $availabilityService)
    {
    }

    public function index(RestaurantIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $restaurants = Restaurant::query()
            ->with(['cuisines', 'media'])
            ->where('status', RestaurantStatus::Active->value)
            ->when($request->filled('q'), function ($query) use ($validated) {
                $query->where(function ($subQuery) use ($validated): void {
                    $subQuery->where('name', 'like', '%'.$validated['q'].'%')
                        ->orWhere('description', 'like', '%'.$validated['q'].'%');
                });
            })
            ->when($request->filled('city'), fn ($query) => $query->where('city', $validated['city']))
            ->when($request->filled('cuisine'), fn ($query) => $query->whereHas('cuisines', function ($subQuery) use ($validated): void {
                $subQuery->where('name', 'like', '%'.$validated['cuisine'].'%');
            }))
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
            $slots = array_values(array_filter($slots, fn (array $slot): bool => str_contains($slot['local_starts_at'], $requestedTime)));
        }

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'timezone' => $restaurant->timezone,
            'slots' => $slots,
        ]);
    }
}
