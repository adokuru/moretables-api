<?php

namespace App\Notifications\Channels;

use App\Notifications\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    public function __construct(protected WhatsAppService $whatsAppService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        if (! $message instanceof WhatsAppMessage) {
            return;
        }

        $recipient = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('WhatsApp notification skipped: notifiable has no WhatsApp route.', [
                'notifiable' => $notifiable::class,
                'notification' => $notification::class,
            ]);

            return;
        }

        $this->whatsAppService->send($recipient, $message);
    }
}
