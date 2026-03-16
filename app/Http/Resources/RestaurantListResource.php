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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status?->value,
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
        ];
    }
}
