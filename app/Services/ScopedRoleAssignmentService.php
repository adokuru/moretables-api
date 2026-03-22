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

    public function assignRestaurantPrincipalAdmin(User $user, Restaurant $restaurant, int $assignedBy): void
    {
        $this->syncRestaurantRole(
            user: $user,
            restaurant: $restaurant,
            roleName: Role::PrincipalAdmin,
            assignedBy: $assignedBy,
        );
    }

    public function syncRestaurantRole(User $user, Restaurant $restaurant, string $roleName, int $assignedBy): UserRole
    {
        UserRole::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('role', fn ($query) => $query->whereIn('name', Role::allRestaurantStaffRoles()))
            ->delete();

        return $this->assignRole(
            user: $user,
            roleName: $roleName,
            organization: $restaurant->organization,
            restaurant: $restaurant,
            assignedBy: $assignedBy,
        ) ?? throw new \RuntimeException('Unable to assign the requested restaurant role.');
    }

    public function removeRestaurantRoles(User $user, Restaurant $restaurant): void
    {
        UserRole::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('role', fn ($query) => $query->whereIn('name', Role::allRestaurantStaffRoles()))
            ->delete();
    }

    public function assignRole(
        User $user,
        string $roleName,
        ?Organization $organization = null,
        ?Restaurant $restaurant = null,
        ?int $assignedBy = null,
    ): ?UserRole {
        $roleId = Role::query()->where('name', $roleName)->value('id');

        if (! $roleId) {
            return null;
        }

        return UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'organization_id' => $organization?->id,
            'restaurant_id' => $restaurant?->id,
        ], [
            'scope_type' => $restaurant
                ? RoleScopeType::Restaurant->value
                : ($organization ? RoleScopeType::Organization->value : null),
            'assigned_by' => $assignedBy,
        ]);
    }
}
