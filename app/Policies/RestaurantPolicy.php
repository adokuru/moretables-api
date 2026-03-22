<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;

class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::restaurantAccessRoles());
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->canAccessRestaurant($restaurant);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::adminRoles());
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->hasRestaurantPermission('restaurants.manage', $restaurant);
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->hasAnyRole([Role::BusinessAdmin, Role::SuperAdmin]);
    }
}
