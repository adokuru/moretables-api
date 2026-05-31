<?php

namespace App\Models;

use Database\Factories\RestaurantSpecialDayShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSpecialDayShift extends Model
{
    /** @use HasFactory<RestaurantSpecialDayShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_special_day_id',
        'restaurant_meal_type_id',
        'opens_at',
        'closes_at',
    ];

    public function specialDay(): BelongsTo
    {
        return $this->belongsTo(RestaurantSpecialDay::class, 'restaurant_special_day_id');
    }

    public function availabilityPeriod(): BelongsTo
    {
        return $this->belongsTo(RestaurantAvailabilityPeriod::class, 'restaurant_meal_type_id');
    }
}
