<?php

namespace App\Http\Requests\Merchant\Concerns;

use App\Models\Restaurant;

trait AuthorizesRestaurantManageOnboarding
{
    protected function authorizeRestaurantManageOnboarding(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasRestaurantPermission('restaurants.manage', $restaurant);
    }
}
