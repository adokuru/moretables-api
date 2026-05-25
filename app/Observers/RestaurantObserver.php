<?php

namespace App\Observers;

use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;

class RestaurantObserver
{
    /**
     * Seed the 5 default access configs whenever a new restaurant is created.
     */
    public function created(Restaurant $restaurant): void
    {
        foreach (RestaurantAccessConfig::defaults() as $config) {
            RestaurantAccessConfig::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => $config['name'],
                'slug' => $config['slug'],
                'description' => $config['description'],
                'permissions' => $config['permissions'],
                'is_default' => true,
            ]);
        }
    }
}
