<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_ids' => array_values(array_unique(array_filter([
            env('GOOGLE_CLIENT_ID'),
            ...array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))),
        ]))),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'issuers' => [
            'accounts.google.com',
            'https://accounts.google.com',
        ],
        'jwks_url' => env('GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    ],

    'apple' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_CLIENT_IDS', ''))))),
        'issuer' => env('APPLE_ISSUER', 'https://appleid.apple.com'),
        'jwks_url' => env('APPLE_JWKS_URL', 'https://appleid.apple.com/auth/keys'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'campaign_waitlist' => [
        'spreadsheet_id' => env('CAMPAIGN_WAITLIST_SPREADSHEET_ID'),
        'sheet_range' => env('CAMPAIGN_WAITLIST_SHEET_RANGE', 'Waitlist!A:B'),
        'service_account_base64' => env('GOOGLE_SERVICE_ACCOUNT_BASE64'),
    ],

    'whatsapp' => [
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'availability_alert_template' => env('WHATSAPP_AVAILABILITY_ALERT_TEMPLATE', 'table_availability_alert'),
        'reservation_created_template' => env('WHATSAPP_RESERVATION_CREATED_TEMPLATE', 'reservation_created'),
        'reservation_updated_template' => env('WHATSAPP_RESERVATION_UPDATED_TEMPLATE', 'reservation_updated'),
        'reservation_cancelled_template' => env('WHATSAPP_RESERVATION_CANCELLED_TEMPLATE', 'reservation_cancelled'),
        'reservation_guest_added_template' => env('WHATSAPP_RESERVATION_GUEST_ADDED_TEMPLATE', 'reservation_guest_added'),
        'reservation_upcoming_reminder_template' => env('WHATSAPP_RESERVATION_UPCOMING_REMINDER_TEMPLATE', 'reservation_upcoming_reminder'),
        'reservation_templates_have_url_button' => env('WHATSAPP_RESERVATION_TEMPLATES_HAVE_URL_BUTTON', false),
        'reservation_templates_have_cancel_button' => env('WHATSAPP_RESERVATION_TEMPLATES_HAVE_CANCEL_BUTTON', false),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

];
