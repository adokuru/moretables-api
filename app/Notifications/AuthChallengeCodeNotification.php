<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthChallengeCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $code,
        protected string $purpose,
        protected int $expiresInMinutes = 10,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MoreTables verification code')
            ->greeting('Hello!')
            ->line("Use this code to {$this->purpose}:")
            ->line($this->code)
            ->line("This code expires in {$this->expiresInMinutes} minutes.")
            ->line('If you did not request this code, you can ignore this email.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'purpose' => $this->purpose,
        ];
    }
}
