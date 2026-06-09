<?php

namespace App;

enum WaitlistType: string
{
    case Seating = 'seating';
    case AvailabilityAlert = 'availability_alert';
}
