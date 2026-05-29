<?php

namespace App\Models;

use App\TableShape;
use App\TableStatus;
use App\TableType;
use Database\Factories\RestaurantTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTable extends Model
{
    /** @use HasFactory<RestaurantTableFactory> */
    use HasFactory;

    public const DEFAULT_MIN_CAPACITY = 1;

    public const DEFAULT_MAX_CAPACITY = 10;

    protected $attributes = [
        'min_capacity' => self::DEFAULT_MIN_CAPACITY,
        'max_capacity' => self::DEFAULT_MAX_CAPACITY,
    ];

    protected $fillable = [
        'restaurant_id',
        'dining_area_id',
        'name',
        'min_capacity',
        'max_capacity',
        'table_type',
        'shape',
        'status',
        'is_active',
        'sort_order',
        'x_position',
        'y_position',
        'width',
        'height',
        'rotation',
        'color',
        'layout_type',
        'chair_color',
    ];

    protected function casts(): array
    {
        return [
            'table_type' => TableType::class,
            'shape' => TableShape::class,
            'status' => TableStatus::class,
            'is_active' => 'boolean',
            'x_position' => 'integer',
            'y_position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'rotation' => 'integer',
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
