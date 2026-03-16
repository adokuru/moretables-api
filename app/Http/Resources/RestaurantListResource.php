<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status?->value,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'address' => trim(implode(', ', array_filter([$this->address_line_1, $this->city, $this->state]))),
            'phone' => $this->phone,
            'email' => $this->email,
            'description' => $this->description,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck('name')->values()),
            'cover_image' => $this->whenLoaded('media', fn () => optional($this->media->first())->url),
        ];
    }
}
