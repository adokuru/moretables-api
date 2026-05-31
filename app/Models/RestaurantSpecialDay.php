<?php

namespace App\Models;

use Database\Factories\RestaurantSpecialDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantSpecialDay extends Model
{
    /** @use HasFactory<RestaurantSpecialDayFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'date',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(RestaurantSpecialDayShift::class);
    }
}
