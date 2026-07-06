<?php

namespace App\Models;

use Database\Factories\RestaurantServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantServer extends Model
{
    /** @use HasFactory<RestaurantServerFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'color',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
