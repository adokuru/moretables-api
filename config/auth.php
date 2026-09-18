<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo Accounts (App Store / Play Store review)
    |--------------------------------------------------------------------------
    |
    | App-store reviewers cannot receive our OTP emails, so the addresses below
    | always get "code" instead of a random one. Everything else about the
    | challenge is unchanged: it still expires, still counts failed attempts,
    | and is still single-use.
    |
    | "customer" signs into the MoreTables app; "restaurant" signs into
    | MoreTables for Restaurants and owns a self-contained demo restaurant, so
    | reviewers never see a real customer's guest data. Unset email = that
    | account is off. Provision both with: php artisan app:provision-demo
    |
    */

    'demo' => [
        'code' => (string) env('DEMO_LOGIN_CODE', '1234'),

        'customer' => [
            'email' => env('DEMO_CUSTOMER_EMAIL'),
        ],

        'restaurant' => [
            'email' => env('DEMO_RESTAURANT_EMAIL'),
            'password' => env('DEMO_RESTAURANT_PASSWORD'),
            'name' => env('DEMO_RESTAURANT_NAME', 'MoreTables Demo Kitchen'),
        ],

        // Derived, so a demo address can never be provisioned without also
        // getting the fixed code (and vice versa). This is the list
        // AuthChallengeService checks.
        'emails' => array_values(array_unique(array_filter([
            trim((string) env('DEMO_CUSTOMER_EMAIL', '')),
            trim((string) env('DEMO_RESTAURANT_EMAIL', '')),
        ]))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
