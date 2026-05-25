<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantStaffAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // When staff are assigned via an access config, permissions come from there.
        // Otherwise fall back to the global role's permissions.
        $accessConfig = $this->whenLoaded('accessConfig');
        $permissions = $this->when(
            $this->access_config_id !== null
                ? $this->relationLoaded('accessConfig')
                : ($this->relationLoaded('role') && $this->role?->relationLoaded('permissions')),
            function () {
                if ($this->access_config_id !== null && $this->relationLoaded('accessConfig')) {
                    return $this->accessConfig?->permissions ?? [];
                }

                return $this->role?->permissions->pluck('name')->values() ?? [];
            },
        );

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'organization_id' => $this->organization_id,
            'access_config' => RestaurantAccessConfigResource::make($accessConfig),
            'role' => $this->role?->name,
            'permissions' => $permissions,
            'assigned_at' => optional($this->created_at)?->toIso8601String(),
            'assigned_by' => UserResource::make($this->whenLoaded('assignedBy')),
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
