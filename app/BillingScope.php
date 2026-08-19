<?php

namespace App;

enum BillingScope: string
{
    case Organization = 'organization';
    case Restaurant = 'restaurant';

    public static function configured(): self
    {
        return self::tryFrom((string) config('billing.scope')) ?? self::Organization;
    }

    /**
     * Whether a business may hold a subscription its restaurants inherit. When disabled the
     * application falls back to billing each restaurant on its own.
     */
    public static function businessBillingEnabled(): bool
    {
        return self::configured() === self::Organization;
    }
}
