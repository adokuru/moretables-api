<?php

namespace App\Models;

use Database\Factories\RestaurantMealTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantMealType extends Model
{
    /** @use HasFactory<RestaurantMealTypeFactory> */
    use HasFactory;
}
