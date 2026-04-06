<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->fullName(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'bio' => $this->bio,
            'birthday' => optional($this->birthday)?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'auth_method' => $this->auth_method?->value,
            'email_verified_at' => optional($this->email_verified_at)?->toIso8601String(),
            'last_active_at' => optional($this->last_active_at)?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
