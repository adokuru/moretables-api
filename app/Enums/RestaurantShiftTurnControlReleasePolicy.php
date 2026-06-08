<?php

namespace App\Enums;

enum RestaurantShiftTurnControlReleasePolicy: string
{
    case DontRelease = 'dont_release';
    case AtShiftStart = 'at_shift_start';
    case HoursBeforeShift = 'hours_before_shift';
}
