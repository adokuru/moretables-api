<?php

namespace App;

enum BillingPlanSlug: string
{
    case Foundation = 'foundation';
    case Core = 'core';
    case Premium = 'premium';

    public function rank(): int
    {
        return match ($this) {
            self::Foundation => 0,
            self::Core => 1,
            self::Premium => 2,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
