<?php

namespace App\Models;

use Database\Factories\UserRestaurantListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRestaurantListItem extends Model
{
    /** @use HasFactory<UserRestaurantListItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_restaurant_list_id',
        'restaurant_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function restaurantList(): BelongsTo
    {
        return $this->belongsTo(UserRestaurantList::class, 'user_restaurant_list_id');
    }
}
