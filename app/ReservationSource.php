<?php

namespace App;

enum ReservationSource: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case Waitlist = 'waitlist';
}
