<?php

namespace App;

enum OnboardingContactReason: string
{
    case BookADemo = 'book_a_demo';
    case RestaurantOnboarding = 'restaurant_onboarding';
    case Pricing = 'pricing';
    case Partnership = 'partnership';
    case GeneralInquiry = 'general_inquiry';
    case Support = 'support';
    case Other = 'other';
}
