<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'actor_type' => $this->actor_type?->value,
            'actor' => UserResource::make($this->whenLoaded('actorUser')),
            'organization_id' => $this->organization_id,
            'restaurant_id' => $this->restaurant_id,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
