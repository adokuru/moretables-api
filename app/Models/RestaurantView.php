<?php

namespace App\Models;

use Database\Factories\RestaurantViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantView extends Model
{
    /** @use HasFactory<RestaurantViewFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'platform',
        'session_id',
        'ip_address',
        'user_agent',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
