<?php

namespace App\Models;

use Database\Factories\RestaurantServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function assignedTables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'assigned_server_id');
    }
}
