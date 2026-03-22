<?php

namespace App\Models;

use Database\Factories\DiningAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiningArea extends Model
{
    /** @use HasFactory<DiningAreaFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'description',
        'tags',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }
}
