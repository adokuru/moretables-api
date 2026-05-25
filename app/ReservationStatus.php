<?php

namespace App;

enum ReservationStatus: string
{
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case PartiallyArrived = 'partially_arrived';
    case LeftMessage = 'left_message';
    case RunningLate = 'running_late';
    case Seated = 'seated';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
