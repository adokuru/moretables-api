<?php

namespace App\Models;

use App\Enums\RestaurantShiftTurnControlReleasePolicy;
use Database\Factories\RestaurantShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantShift extends Model
{
    /** @use HasFactory<RestaurantShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'restaurant_meal_type_id',
        'name',
        'day_of_week',
        'starts_at',
        'ends_at',
        'color',
        'is_active',
        'turn_control_release_policy',
        'release_hours_before',
        'flow_interval_minutes',
        'flow_default_max_covers',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
            'turn_control_release_policy' => RestaurantShiftTurnControlReleasePolicy::class,
            'release_hours_before' => 'integer',
            'flow_interval_minutes' => 'integer',
            'flow_default_max_covers' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(RestaurantAvailabilityPeriod::class, 'restaurant_meal_type_id');
    }

    public function turnTimes(): HasMany
    {
        return $this->hasMany(RestaurantShiftTurnTime::class)->orderBy('party_size');
    }

    public function tableAvailability(): HasMany
    {
        return $this->hasMany(RestaurantShiftTableAvailability::class);
    }

    public function turnControls(): HasMany
    {
        return $this->hasMany(RestaurantShiftTurnControl::class);
    }

    public function flowIntervals(): HasMany
    {
        return $this->hasMany(RestaurantShiftFlowInterval::class)->orderBy('starts_at');
    }
}
