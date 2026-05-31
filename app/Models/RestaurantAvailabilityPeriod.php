<?php

namespace App\Models;

use Database\Factories\RestaurantAvailabilityPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantAvailabilityPeriod extends Model
{
    /** @use HasFactory<RestaurantAvailabilityPeriodFactory> */
    use HasFactory;

    protected $table = 'restaurant_meal_types';

    protected $fillable = ['restaurant_id', 'name', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RestaurantAvailabilitySchedule::class, 'restaurant_meal_type_id');
    }

    public function specialDayShifts(): HasMany
    {
        return $this->hasMany(RestaurantSpecialDayShift::class, 'restaurant_meal_type_id');
    }
}
