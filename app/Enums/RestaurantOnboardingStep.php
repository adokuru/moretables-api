<?php

declare(strict_types=1);

namespace App\Enums;

enum RestaurantOnboardingStep: string
{
    case Basics = 'basics';
    case Location = 'location';
    case Cuisine = 'cuisine';
    case Hours = 'hours';
    case Meals = 'meals';
    case Media = 'media';
    case InternalNotes = 'internal_notes';
    case Policies = 'policies';
    case Review = 'review';
    case Published = 'published';
}
