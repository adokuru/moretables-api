<?php

namespace App\Models;

use Database\Factories\UserRestaurantListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserRestaurantList extends Model
{
    /** @use HasFactory<UserRestaurantListFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_private',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserRestaurantListItem::class)->orderBy('sort_order');
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'user_restaurant_list_items')
            ->withPivot(['id', 'sort_order'])
            ->withTimestamps()
            ->orderBy('user_restaurant_list_items.sort_order');
    }
}
