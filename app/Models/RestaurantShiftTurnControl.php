<?php

namespace App\Models;

use App\Enums\RestaurantShiftTurnControlRuleType;
use Database\Factories\RestaurantShiftTurnControlFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantShiftTurnControl extends Model
{
    /** @use HasFactory<RestaurantShiftTurnControlFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_shift_id',
        'rule_type',
        'party_size',
        'restaurant_table_id',
        'min_turns',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => RestaurantShiftTurnControlRuleType::class,
            'party_size' => 'integer',
            'min_turns' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RestaurantShift::class, 'restaurant_shift_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }
}
