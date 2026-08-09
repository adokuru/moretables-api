<?php

namespace App\Listeners\Concerns;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

trait NotifiesRestaurantStaff
{
    /**
     * Every user who can access this restaurant's /dashboard — mirrors
     * User::canAccessRestaurant()'s scoping, minus the global-admin branch
     * (platform admins don't need a bell notification for every restaurant's
     * every reservation/alert).
     *
     * @return Collection<int, User>
     */
    private function restaurantStaff(Restaurant $restaurant): Collection
    {
        return User::query()
            ->whereNotNull('email')
            ->whereHas('roleAssignments', function ($query) use ($restaurant): void {
                $query
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('name', Role::restaurantAccessRoles()))
                    ->where(function ($scopeQuery) use ($restaurant): void {
                        $scopeQuery
                            ->where('restaurant_id', $restaurant->id)
                            ->orWhere(function ($orgQuery) use ($restaurant): void {
                                $orgQuery
                                    ->where('organization_id', $restaurant->organization_id)
                                    ->whereNull('restaurant_id');
                            });
                    });
            })
            ->get()
            ->unique('email');
    }
}
