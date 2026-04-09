<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'primary_contact_name' => $this->primary_contact_name,
            'primary_contact_email' => $this->primary_contact_email,
            'primary_contact_phone' => $this->primary_contact_phone,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
            'website' => $this->website,
            'billing_email' => $this->billing_email,
            'tax_id' => $this->tax_id,
            'registration_number' => $this->registration_number,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'restaurants_count' => $this->whenCounted('restaurants'),
            'restaurants' => RestaurantListResource::collection($this->whenLoaded('restaurants')),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
