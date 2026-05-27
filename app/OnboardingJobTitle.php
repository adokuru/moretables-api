<?php

namespace App;

enum OnboardingJobTitle: string
{
    case Owner = 'owner';
    case GeneralManager = 'general_manager';
    case OperationsManager = 'operations_manager';
    case MarketingManager = 'marketing_manager';
    case ExecutiveChef = 'executive_chef';
    case Other = 'other';
}
