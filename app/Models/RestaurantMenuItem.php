<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantMenuItem extends Model
{
    /** @use HasFactory<\Database\Factories\RestaurantMenuItemFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'section_name',
        'item_name',
        'description',
        'price',
        'currency',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
