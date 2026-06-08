<?php

namespace Database\Factories;

use App\Enums\RestaurantShiftTurnControlRuleType;
use App\Models\RestaurantShift;
use App\Models\RestaurantShiftTurnControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantShiftTurnControl>
 */
class RestaurantShiftTurnControlFactory extends Factory
{
    protected $model = RestaurantShiftTurnControl::class;

    public function definition(): array
    {
        return [
            'restaurant_shift_id' => RestaurantShift::factory(),
            'rule_type' => RestaurantShiftTurnControlRuleType::PartySize,
            'party_size' => 2,
            'restaurant_table_id' => null,
            'min_turns' => 2,
        ];
    }
}
