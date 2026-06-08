<?php

namespace App\Enums;

enum RestaurantShiftTurnControlRuleType: string
{
    case PartySize = 'party_size';
    case Table = 'table';
}
