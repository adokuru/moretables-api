<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantShiftNote extends Model
{
    protected $fillable = [
        'restaurant_id',
        'restaurant_shift_id',
        'created_by_user_id',
        'service_starts_at',
        'service_ends_at',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'service_starts_at' => 'immutable_datetime',
            'service_ends_at' => 'immutable_datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RestaurantShift::class, 'restaurant_shift_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
