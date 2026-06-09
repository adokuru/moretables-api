<?php

return [
    'upcoming_reminder_days_before' => array_map(
        'intval',
        array_filter(explode(',', (string) env('RESERVATION_UPCOMING_REMINDER_DAYS_BEFORE', '3,1')))
    ),
    'upcoming_reminder_window_minutes' => (int) env('RESERVATION_UPCOMING_REMINDER_WINDOW_MINUTES', 60),
    'no_show_grace_minutes' => (int) env('RESERVATION_NO_SHOW_GRACE_MINUTES', 60),
    'no_show_system_user_email' => env('RESERVATION_NO_SHOW_SYSTEM_USER_EMAIL', 'automation@moretables.internal'),
    'no_show_eligible_statuses' => [
        'booked',
        'confirmed',
        'running_late',
        'left_message',
    ],

    'availability_alerts' => [
        'renotify_cooldown_minutes' => (int) env('AVAILABILITY_ALERT_RENOTIFY_COOLDOWN_MINUTES', 30),
        'max_active_per_user' => (int) env('AVAILABILITY_ALERT_MAX_ACTIVE_PER_USER', 20),
    ],
];
