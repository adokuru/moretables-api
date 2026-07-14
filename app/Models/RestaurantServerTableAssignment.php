<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantServerTableAssignment extends Model
{
    protected $fillable = [
        'restaurant_id',
        'restaurant_table_id',
        'restaurant_server_id',
        'service_starts_at',
        'service_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'service_starts_at' => 'datetime',
            'service_ends_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(RestaurantServer::class, 'restaurant_server_id');
    }
}
