<?php

namespace App\Models;

use Database\Factories\RestaurantPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPolicy extends Model
{
    /** @use HasFactory<RestaurantPolicyFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'reservation_duration_minutes',
        'booking_window_days',
        'cancellation_cutoff_hours',
        'min_party_size',
        'max_party_size',
        'deposit_required',
    ];

    protected function casts(): array
    {
        return [
            'deposit_required' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
