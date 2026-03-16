<?php

namespace App\Notifications;

use App\Services\ExpoPushService;
use Illuminate\Notifications\Notification;

class ExpoPushChannel
{
    public function __construct(protected ExpoPushService $expoPushService)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpoPush')) {
            return;
        }

        $message = $notification->toExpoPush($notifiable);

        if (! $message instanceof ExpoPushMessage || ! method_exists($notifiable, 'expoPushTokens')) {
            return;
        }

        $tokens = $notifiable->expoPushTokens()
            ->latest('last_seen_at')
            ->pluck('expo_token')
            ->filter()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $this->expoPushService->send($tokens, $message);
    }
}
