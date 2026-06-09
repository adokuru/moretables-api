<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\ExpoPushChannel;

trait UsesNotificationQueues
{
    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'mail' => 'notifications',
            'database' => 'notifications',
            ExpoPushChannel::class => 'notifications',
            WhatsAppChannel::class => 'notifications',
        ];
    }
}
