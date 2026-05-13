<?php

namespace App\Models;

use Database\Factories\RestaurantMealTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantMealType extends Model
{
    /** @use HasFactory<RestaurantMealTypeFactory> */
    use HasFactory;

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
        return $this->hasMany(RestaurantMealSchedule::class);
    }
}
