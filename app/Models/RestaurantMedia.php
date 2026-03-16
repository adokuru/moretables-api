<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantMedia extends Model
{
    /** @use HasFactory<\Database\Factories\RestaurantMediaFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'collection',
        'url',
        'alt_text',
        'sort_order',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
