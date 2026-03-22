<?php

namespace App\Models;

use App\TableStatus;
use Database\Factories\RestaurantTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTable extends Model
{
    /** @use HasFactory<RestaurantTableFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'dining_area_id',
        'name',
        'min_capacity',
        'max_capacity',
        'status',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function diningArea(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
