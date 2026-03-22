<?php

namespace App\Models;

use Database\Factories\SavedRestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedRestaurant extends Model
{
    /** @use HasFactory<SavedRestaurantFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'restaurant_id',
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
