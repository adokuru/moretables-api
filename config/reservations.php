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
];
