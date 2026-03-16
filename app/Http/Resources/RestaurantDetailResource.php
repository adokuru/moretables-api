<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status?->value,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'description' => $this->description,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck('name')->values()),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($media) => [
                'id' => $media->id,
                'collection' => $media->collection,
                'url' => $media->url,
                'alt_text' => $media->alt_text,
            ])->values()),
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'opens_at' => $hour->opens_at,
                'closes_at' => $hour->closes_at,
                'is_closed' => $hour->is_closed,
            ])->values()),
            'policy' => $this->whenLoaded('policy', fn () => [
                'reservation_duration_minutes' => $this->policy?->reservation_duration_minutes,
                'booking_window_days' => $this->policy?->booking_window_days,
                'cancellation_cutoff_hours' => $this->policy?->cancellation_cutoff_hours,
                'min_party_size' => $this->policy?->min_party_size,
                'max_party_size' => $this->policy?->max_party_size,
                'deposit_required' => $this->policy?->deposit_required,
            ]),
            'dining_areas' => DiningAreaResource::collection($this->whenLoaded('diningAreas')),
        ];
    }
}
