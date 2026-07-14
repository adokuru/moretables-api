<?php

namespace App;

enum ReservationCardHoldStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case VerificationFailed = 'verification_failed';
    case Charged = 'charged';
    case ChargeFailed = 'charge_failed';
}
