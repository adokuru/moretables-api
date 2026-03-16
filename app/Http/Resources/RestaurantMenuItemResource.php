<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantMenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $featuredImage = null;
        $galleryImages = collect();

        if ($this->relationLoaded('media')) {
            $featuredImage = $this->media->firstWhere('collection_name', 'featured');
            $galleryImages = $this->media
                ->where('collection_name', 'gallery')
                ->sortBy('order_column')
                ->values();
        }

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'section_name' => $this->section_name,
            'name' => $this->item_name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'sort_order' => $this->sort_order,
            'featured_image' => $featuredImage ? MediaAssetResource::make($featuredImage) : null,
            'gallery_images' => MediaAssetResource::collection($galleryImages),
        ];
    }
}
