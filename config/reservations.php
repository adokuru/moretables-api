<?php

$upcomingReminderDays = array_values(array_filter(
    array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('RESERVATION_UPCOMING_REMINDER_DAYS_BEFORE', '3,1'))
    ),
    static fn (int $value): bool => $value > 0,
));

return [
    'upcoming_reminder_days_before' => $upcomingReminderDays,
    'upcoming_reminder_window_minutes' => (int) env('RESERVATION_UPCOMING_REMINDER_WINDOW_MINUTES', 60),
];
