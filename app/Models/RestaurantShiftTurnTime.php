<?php

namespace App\Models;

use Database\Factories\RestaurantShiftTurnTimeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantShiftTurnTime extends Model
{
    /** @use HasFactory<RestaurantShiftTurnTimeFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_shift_id',
        'party_size',
        'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RestaurantShift::class, 'restaurant_shift_id');
    }
}
