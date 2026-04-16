<?php

namespace App\Http\Resources;

use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ?Media $profilePicture */
        $profilePicture = $this->relationLoaded('media')
            ? $this->media->firstWhere('collection_name', 'profile_picture')
            : $this->getFirstMedia('profile_picture');

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
            'account_type' => $this->accountType(),
            'email_verified_at' => optional($this->email_verified_at)?->toIso8601String(),
            'last_active_at' => optional($this->last_active_at)?->toIso8601String(),
            'profile_picture' => $profilePicture ? MediaAssetResource::make($profilePicture) : null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'role_assignments' => $this->whenLoaded('roleAssignments', fn () => $this->roleAssignments
                ->map(function (UserRole $assignment): array {
                    return [
                        'role' => $assignment->role?->name,
                        'scope_type' => $assignment->scope_type?->value,
                        'organization' => $assignment->organization
                            ? [
                                'id' => $assignment->organization->id,
                                'name' => $assignment->organization->name,
                                'slug' => $assignment->organization->slug,
                            ]
                            : null,
                        'restaurant' => $assignment->restaurant
                            ? [
                                'id' => $assignment->restaurant->id,
                                'name' => $assignment->restaurant->name,
                                'slug' => $assignment->restaurant->slug,
                            ]
                            : null,
                    ];
                })
                ->values()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
