<?php

namespace App\Models;

use Database\Factories\RestaurantShiftFlowIntervalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantShiftFlowInterval extends Model
{
    /** @use HasFactory<RestaurantShiftFlowIntervalFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_shift_id',
        'starts_at',
        'max_covers',
    ];

    protected function casts(): array
    {
        return [
            'max_covers' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RestaurantShift::class, 'restaurant_shift_id');
    }
}
