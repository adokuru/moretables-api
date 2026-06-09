<?php

namespace App\Observers;

use App\Models\DiningArea;
use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;

class RestaurantObserver
{
    /**
     * Seed the 5 default access configs whenever a new restaurant is created.
     */
    public function created(Restaurant $restaurant): void
    {
        $restaurant->diningAreas()->create([
            'name' => DiningArea::DEFAULT_NAME,
            'floor_type' => DiningArea::DEFAULT_FLOOR_TYPE,
            'is_active' => true,
            'sort_order' => 0,
        ]);

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
