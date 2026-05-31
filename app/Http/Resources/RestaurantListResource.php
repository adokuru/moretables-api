<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RestaurantListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ?Media $featuredImage */
        $featuredImage = null;

        if ($this->relationLoaded('media')) {
            $featuredImage = $this->media->firstWhere('collection_name', 'featured')
                ?? $this->media->where('collection_name', 'gallery')->sortBy('order_column')->first();
        }

        $discoveryMetrics = array_filter([
            'bookings_count' => $this->resource->getAttribute('bookings_count'),
            'views_count' => $this->resource->getAttribute('views_count'),
            'saves_count' => $this->resource->getAttribute('saves_count'),
            'list_adds_count' => $this->resource->getAttribute('list_adds_count'),
            'reviews_count' => $this->resource->getAttribute('reviews_count'),
            'average_rating' => $this->resource->getAttribute('average_rating') !== null
                ? round((float) $this->resource->getAttribute('average_rating'), 2)
                : null,
        ], static fn ($value): bool => $value !== null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status?->value,
            'is_featured' => (bool) $this->is_featured,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'average_price_range' => $this->average_price_range,
            'address' => trim(implode(', ', array_filter([$this->address_line_1, $this->city, $this->state]))),
            'phone' => $this->phone,
            'email' => $this->email,
            'description' => $this->description,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck('name')->values()),
            'hours' => $this->when(
                $this->relationLoaded('availabilitySchedules') || $this->relationLoaded('hours'),
                fn () => $this->formattedHours(),
            ),
            'reservation_times' => $this->when(
                $this->resource->getAttribute('reservation_times') !== null,
                fn () => $this->resource->getAttribute('reservation_times'),
            ),
            'has_saved' => $this->when(
                $this->resource->getAttribute('has_saved') !== null,
                fn () => (bool) $this->resource->getAttribute('has_saved'),
            ),
            'distance_km' => $this->when(
                $this->resource->getAttribute('distance_km') !== null,
                fn () => round((float) $this->resource->getAttribute('distance_km'), 2),
            ),
            'cover_image' => $featuredImage?->getAvailableUrl(['card']),
            'featured_image' => $featuredImage ? MediaAssetResource::make($featuredImage) : null,
            'discovery_metrics' => $this->when($discoveryMetrics !== [], $discoveryMetrics),
        ];
    }

    protected function formattedHours(): Collection
    {
        if ($this->relationLoaded('availabilitySchedules') && $this->availabilitySchedules->isNotEmpty()) {
            $schedulesByDay = $this->availabilitySchedules->groupBy('day_of_week');

            return collect(range(0, 6))->map(function (int $dayOfWeek) use ($schedulesByDay): array {
                $schedules = $schedulesByDay->get($dayOfWeek, collect());

                if ($schedules->isEmpty()) {
                    return [
                        'day_of_week' => $dayOfWeek,
                        'opens_at' => null,
                        'closes_at' => null,
                        'is_closed' => true,
                    ];
                }

                return [
                    'day_of_week' => $dayOfWeek,
                    'opens_at' => $schedules->min('opens_at'),
                    'closes_at' => $schedules->max('closes_at'),
                    'is_closed' => false,
                ];
            })->values();
        }

        return $this->hours->map(fn ($hour) => [
            'day_of_week' => $hour->day_of_week,
            'opens_at' => $hour->opens_at,
            'closes_at' => $hour->closes_at,
            'is_closed' => $hour->is_closed,
        ])->values();
    }
}
