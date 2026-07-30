<?php

namespace App\Listeners;

use App\Notifications\GuestSurveyInvitationNotification;
use Illuminate\Notifications\Events\NotificationSent;

class MarkGuestSurveyInvitationSent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof GuestSurveyInvitationNotification) {
            return;
        }

        $event->notification->guestSurveyInvitation()->update(['sent_at' => now()]);
    }
}
