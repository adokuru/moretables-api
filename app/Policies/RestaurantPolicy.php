<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            'organization_owner',
            'restaurant_manager',
            'restaurant_staff',
            'business_admin',
            'dev_admin',
            'super_admin',
        ]);
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->canManageRestaurant($restaurant);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['business_admin', 'dev_admin', 'super_admin']);
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->canManageRestaurant($restaurant);
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->hasAnyRole(['business_admin', 'super_admin']);
    }
}
