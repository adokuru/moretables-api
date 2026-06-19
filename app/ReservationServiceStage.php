<?php

namespace App;

enum ReservationServiceStage: string
{
    case PartiallySeated = 'partially_seated';
    case Seated = 'seated';
    case Appetizer = 'appetizer';
    case Entree = 'entree';
    case Dessert = 'dessert';
    case Cleared = 'cleared';
    case CheckDropped = 'check_dropped';
    case Paid = 'paid';
}
