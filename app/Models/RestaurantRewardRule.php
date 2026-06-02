<?php

namespace App\Models;

use Database\Factories\RestaurantRewardRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantRewardRule extends Model
{
    /** @use HasFactory<RestaurantRewardRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'points',
        'days',
        'times',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'days' => 'array',
            'times' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @param  Builder<RestaurantRewardRule>  $query
     * @return Builder<RestaurantRewardRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
