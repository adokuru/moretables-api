<?php

namespace App;

enum WaitlistExpiryReason: string
{
    case TimeExpired = 'time_expired';
    case TableUnavailable = 'table_unavailable';
}
