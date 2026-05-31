<?php

return [
    'cache' => [
        'namespace' => env('PERFORMANCE_CACHE_NAMESPACE', 'v1'),
        'ttls' => [
            'public_fragments' => [
                (int) env('CACHE_PUBLIC_FRESH_SECONDS', 60),
                (int) env('CACHE_PUBLIC_STALE_SECONDS', 300),
            ],
            'review_summaries' => [
                (int) env('CACHE_REVIEW_SUMMARY_FRESH_SECONDS', 300),
                (int) env('CACHE_REVIEW_SUMMARY_STALE_SECONDS', 900),
            ],
            'cuisine_options' => [
                (int) env('CACHE_CUISINE_OPTIONS_FRESH_SECONDS', 3600),
                (int) env('CACHE_CUISINE_OPTIONS_STALE_SECONDS', 21600),
            ],
            'merchant_overview' => [
                (int) env('CACHE_MERCHANT_OVERVIEW_FRESH_SECONDS', 15),
                (int) env('CACHE_MERCHANT_OVERVIEW_STALE_SECONDS', 60),
            ],
            'admin_dashboard' => [
                (int) env('CACHE_ADMIN_DASHBOARD_FRESH_SECONDS', 30),
                (int) env('CACHE_ADMIN_DASHBOARD_STALE_SECONDS', 120),
            ],
            'billing_eligibility' => (int) env('CACHE_BILLING_ELIGIBILITY_SECONDS', 60),
            'restaurant_authorization' => (int) env('CACHE_RESTAURANT_AUTHORIZATION_SECONDS', 300),
            'view_deduplication' => (int) env('CACHE_VIEW_DEDUPLICATION_SECONDS', 1800),
        ],
    ],

    'locks' => [
        'reservation_seconds' => (int) env('RESERVATION_LOCK_SECONDS', 10),
        'reservation_wait_seconds' => (int) env('RESERVATION_LOCK_WAIT_SECONDS', 3),
    ],

    'rate_limits' => [
        'public_reads' => (int) env('RATE_LIMIT_PUBLIC_READS_PER_MINUTE', 180),
        'public_expensive' => (int) env('RATE_LIMIT_PUBLIC_EXPENSIVE_PER_MINUTE', 60),
        'public_writes' => (int) env('RATE_LIMIT_PUBLIC_WRITES_PER_MINUTE', 30),
        'otp_identity' => (int) env('RATE_LIMIT_OTP_IDENTITY_PER_MINUTE', 5),
        'otp_ip' => (int) env('RATE_LIMIT_OTP_IP_PER_MINUTE', 30),
        'otp_verification' => (int) env('RATE_LIMIT_OTP_VERIFICATION_PER_MINUTE', 10),
        'customer_api' => (int) env('RATE_LIMIT_CUSTOMER_API_PER_MINUTE', 180),
        'merchant_api' => (int) env('RATE_LIMIT_MERCHANT_API_PER_MINUTE', 600),
        'admin_api' => (int) env('RATE_LIMIT_ADMIN_API_PER_MINUTE', 300),
    ],

    'monitoring' => [
        'database_connections' => (int) env('DB_MONITOR_MAX_CONNECTIONS', 100),
        'queue_backlog' => (int) env('QUEUE_MONITOR_MAX_JOBS', 100),
        'query_time_warning_milliseconds' => (int) env('DB_QUERY_TIME_WARNING_MILLISECONDS', 500),
    ],
];
