<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
            'address' => trim(implode(', ', array_filter([$this->address_line_1, $this->city, $this->state]))),
            'phone' => $this->phone,
            'email' => $this->email,
            'description' => $this->description,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck('name')->values()),
            'cover_image' => $featuredImage?->getAvailableUrl(['card']),
            'featured_image' => $featuredImage ? MediaAssetResource::make($featuredImage) : null,
            'discovery_metrics' => $this->when($discoveryMetrics !== [], $discoveryMetrics),
        ];
    }
}
