<?php

namespace App;

enum AuthChallengeStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
