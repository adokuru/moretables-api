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
            'restaurant_name' => $this->restaurant_name,
            'owner_name' => $this->owner_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
            'reviewed_by' => UserResource::make($this->whenLoaded('reviewedBy')),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
