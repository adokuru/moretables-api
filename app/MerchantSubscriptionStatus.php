<?php

namespace App;

enum MerchantSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
