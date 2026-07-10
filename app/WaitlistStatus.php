<?php

namespace App;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Notified = 'notified';
    case Arrived = 'arrived';
    case PartiallyArrived = 'partially_arrived';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Seated = 'seated';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
