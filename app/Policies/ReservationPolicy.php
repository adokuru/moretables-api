<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $reservation->user_id === $user->id || $user->canManageRestaurant($reservation->restaurant);
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $reservation->user_id === $user->id || $user->canManageRestaurant($reservation->restaurant);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $reservation->user_id === $user->id || $user->canManageRestaurant($reservation->restaurant);
    }
}
