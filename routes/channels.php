<?php

use App\Models\Restaurant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('restaurant.{restaurantId}', function ($user, int $restaurantId) {
    $restaurant = Restaurant::query()->find($restaurantId);

    return $restaurant ? $user->canAccessRestaurant($restaurant) : false;
});
