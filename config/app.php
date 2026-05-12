<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Base URLs
    |--------------------------------------------------------------------------
    |
    | MoreTables ships three distinct frontends. Each one has its own canonical
    | origin and is consumed by different audiences. These base URLs are the
    | single source of truth and should be used by anything that needs to link
    | from the API back into a frontend (password reset, email verification,
    | magic link, deep link emails, marketing CTAs, etc.).
    |
    | - main: customer-facing app (e.g. https://www.moretables.com)
    | - admin: internal admin/back-office app (e.g. https://admin.moretables.com)
    | - restaurant: merchant/staff app for owners + restaurant staff
    | (e.g. https://restaurant.moretables.com)
    |
    */

    'frontend_urls' => [
        'main' => env('FRONTEND_URL'),
        'admin' => env('ADMIN_FRONTEND_URL'),
        'restaurant' => env('RESTAURANT_FRONTEND_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Path Overrides
    |--------------------------------------------------------------------------
    |
    | Default paths appended to each frontend base URL for specific flows.
    | Override via env only if a frontend renames a route. Each path may
    | contain a "{token}" placeholder; otherwise the consumer decides how
    | the token is appended (path segment vs. query parameter).
    |
    */

    'frontend_paths' => [
        'password_reset' => [
            'main' => env('FRONTEND_PASSWORD_RESET_PATH', '/reset-password'),
            'admin' => env('ADMIN_FRONTEND_PASSWORD_RESET_PATH', '/change-password'),
            'restaurant' => env('RESTAURANT_FRONTEND_PASSWORD_RESET_PATH', '/auth/change-password'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
