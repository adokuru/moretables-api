<?php

namespace App\Models;

use App\TableType;
use Database\Factories\RestaurantShiftTableAvailabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantShiftTableAvailability extends Model
{
    /** @use HasFactory<RestaurantShiftTableAvailabilityFactory> */
    use HasFactory;

    protected $table = 'restaurant_shift_table_availability';

    protected $fillable = [
        'restaurant_shift_id',
        'dining_area_id',
        'table_type',
        'include_combinations',
        'is_reservable',
    ];

    protected function casts(): array
    {
        return [
            'table_type' => TableType::class,
            'include_combinations' => 'boolean',
            'is_reservable' => 'boolean',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RestaurantShift::class, 'restaurant_shift_id');
    }

    public function diningArea(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class);
    }
}
