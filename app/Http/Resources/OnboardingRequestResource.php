<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim(implode(' ', array_filter([$this->first_name, $this->last_name]))) ?: $this->owner_name,
            'restaurant_name' => $this->restaurant_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title?->value,
            'location_count' => $this->location_count?->value,
            'contact_reason' => $this->contact_reason?->value,
            'address' => $this->address,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
            'reviewed_by' => UserResource::make($this->whenLoaded('reviewedBy')),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
