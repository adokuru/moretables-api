<?php

namespace App;

enum OnboardingContactReason: string
{
    case BookADemo = 'book_a_demo';
    case Pricing = 'pricing';
    case Partnership = 'partnership';
    case Support = 'support';
    case Other = 'other';
}
