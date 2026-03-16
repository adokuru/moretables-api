<?php

namespace App;

enum RestaurantStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
}
