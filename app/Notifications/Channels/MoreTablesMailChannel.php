<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\EmailUnsubscribe;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MoreTablesMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        if ($notification instanceof Unsubscribable && $this->isUnsubscribed($notifiable, $notification)) {
            return;
        }

        return parent::send($notifiable, $notification);
    }

    /**
     * @param  MailMessage  $message
     * @return \Closure
     */
    protected function buildMarkdownText($message)
    {
        return fn (array $data) => $this->markdownRenderer($message)->renderText(
            $message->markdown,
            array_merge($data, $message->data()),
        );
    }

    protected function isUnsubscribed(object $notifiable, Notification $notification): bool
    {
        $recipients = $notifiable->routeNotificationFor('mail', $notification);

        if (is_string($recipients)) {
            $recipients = [$recipients];
        }

        if (! is_array($recipients) || $recipients === []) {
            return false;
        }

        foreach ($recipients as $key => $value) {
            $email = is_string($key) ? $key : $value;

            if (is_string($email) && $email !== '' && EmailUnsubscribe::isSuppressed($email)) {
                return true;
            }
        }

        return false;
    }
}
