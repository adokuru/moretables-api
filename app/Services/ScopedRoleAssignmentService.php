<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\RoleScopeType;

class ScopedRoleAssignmentService
{
    public function assignOrganizationOwner(User $user, Organization $organization, int $assignedBy): void
    {
        $this->assignRole(
            user: $user,
            roleName: Role::OrganizationOwner,
            organization: $organization,
            assignedBy: $assignedBy,
        );
    }

    public function assignRestaurantManager(User $user, Restaurant $restaurant, int $assignedBy): void
    {
        $this->assignRole(
            user: $user,
            roleName: Role::RestaurantManager,
            organization: $restaurant->organization,
            restaurant: $restaurant,
            assignedBy: $assignedBy,
        );
    }

    public function assignRole(
        User $user,
        string $roleName,
        ?Organization $organization = null,
        ?Restaurant $restaurant = null,
        ?int $assignedBy = null,
    ): void {
        $roleId = Role::query()->where('name', $roleName)->value('id');

        if (! $roleId) {
            return;
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'organization_id' => $organization?->id,
            'restaurant_id' => $restaurant?->id,
        ], [
            'scope_type' => $restaurant ? RoleScopeType::Restaurant->value : RoleScopeType::Organization->value,
            'assigned_by' => $assignedBy,
        ]);
    }
}
