<?php

return [
    'default_provider' => env('BILLING_PROVIDER', 'paystack'),

    'frontend_billing_url' => env('RESTAURANT_FRONTEND_BILLING_URL', rtrim((string) env('RESTAURANT_FRONTEND_URL', 'https://restaurant.moretables.com'), '/').'/billing'),

    // ponytail: Paystack cannot tokenize a card at zero amount, so verification charges a nominal
    // (refundable) amount to save the authorization. The real no-show fee is the policy hold_charge_amount.
    'card_hold' => [
        'verification_amount' => (int) env('CARD_HOLD_VERIFICATION_AMOUNT', 5000),
        'currency' => env('CARD_HOLD_CURRENCY', 'NGN'),
        'callback_url' => env('CARD_HOLD_CALLBACK_URL', env('PAYSTACK_CALLBACK_URL')),
    ],

    'providers' => [
        'paystack' => [
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'callback_url' => env('PAYSTACK_CALLBACK_URL'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET') ?: env('PAYSTACK_SECRET_KEY'),
        ],
    ],

    'plans' => [
        [
            'name' => 'Foundation',
            'slug' => 'foundation',
            'description' => 'Core reservation and restaurant management tools for small teams.',
            'amount' => 8500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'provider' => 'paystack',
            'provider_plan_code' => env('PAYSTACK_FOUNDATION_PLAN_CODE'),
            'features' => [
                'reservations' => true,
                'floor_plan' => true,
                'guest_communication' => false,
            ],
        ],
        [
            'name' => 'Core',
            'slug' => 'core',
            'description' => 'Advanced operations for growing restaurants.',
            'amount' => 13500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'provider' => 'paystack',
            'provider_plan_code' => env('PAYSTACK_CORE_PLAN_CODE'),
            'features' => [
                'reservations' => true,
                'floor_plan' => true,
                'guest_communication' => true,
            ],
        ],
        [
            'name' => 'Premium',
            'slug' => 'premium',
            'description' => 'Full merchant suite for restaurants with larger teams and higher volume.',
            'amount' => 18500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'provider' => 'paystack',
            'provider_plan_code' => env('PAYSTACK_PREMIUM_PLAN_CODE'),
            'features' => [
                'reservations' => true,
                'floor_plan' => true,
                'guest_communication' => true,
                'priority_support' => true,
            ],
        ],
    ],
];
