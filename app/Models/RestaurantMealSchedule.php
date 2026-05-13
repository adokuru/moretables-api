<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantMealSchedule extends Model
{
    protected $fillable = ['restaurant_id', 'restaurant_meal_type_id', 'day_of_week', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(RestaurantMealType::class, 'restaurant_meal_type_id');
    }
}
