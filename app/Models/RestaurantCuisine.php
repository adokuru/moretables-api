<?php

namespace App\Models;

use Database\Factories\RestaurantCuisineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantCuisine extends Model
{
    /** @use HasFactory<RestaurantCuisineFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
