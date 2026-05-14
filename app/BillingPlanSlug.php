<?php

namespace App;

enum BillingPlanSlug: string
{
    case Foundation = 'foundation';
    case Core = 'core';
    case Premium = 'premium';
}
