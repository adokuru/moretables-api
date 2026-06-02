<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoretableLineupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cover = $this->relationLoaded('media') ? $this->getFirstMedia('cover') : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'cover_image' => $cover ? MediaAssetResource::make($cover) : null,
            'status' => $this->status?->value,
            'is_featured' => (bool) $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),
            'distance_km' => $this->when(
                $this->resource->getAttribute('distance_km') !== null,
                fn () => round((float) $this->resource->getAttribute('distance_km'), 2),
            ),
            'restaurant' => RestaurantListResource::make($this->whenLoaded('restaurant')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
