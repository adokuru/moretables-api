<?php

namespace App;

enum TableStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Occupied = 'occupied';
    case Unavailable = 'unavailable';
}
