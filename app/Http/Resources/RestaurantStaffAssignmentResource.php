<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantStaffAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'organization_id' => $this->organization_id,
            'role' => $this->role?->name,
            'permissions' => $this->when(
                $this->relationLoaded('role') && $this->role?->relationLoaded('permissions'),
                fn () => $this->role?->permissions->pluck('name')->values() ?? [],
            ),
            'assigned_at' => optional($this->created_at)?->toIso8601String(),
            'assigned_by' => UserResource::make($this->whenLoaded('assignedBy')),
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
