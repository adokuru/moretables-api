<?php

namespace App;

enum RewardPointTransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjustment = 'adjustment';
    case Expire = 'expire';
}
