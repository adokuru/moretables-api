<?php

namespace App\Services;

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingAcknowledgementNotification;
use App\Notifications\OnboardingDemoInvitationNotification;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\OnboardingContactReason;
use App\UserStatus;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

class OnboardingRequestNotificationService
{
    public function notifySubmission(OnboardingRequest $onboardingRequest): void
    {
        $admins = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', Role::adminRoles()))
            ->get();

        Notification::send($admins, new OnboardingRequestSubmittedNotification($onboardingRequest));
        Notification::route('mail', 'sales@moretables.com')
            ->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));

        if (blank($onboardingRequest->email)) {
            return;
        }

        Notification::route('mail', $onboardingRequest->email)
            ->notify($this->replyFor($onboardingRequest));

        if ($onboardingRequest->contact_reason === OnboardingContactReason::BookADemo) {
            $onboardingRequest->forceFill(['demo_invitation_sent_at' => now()])->save();
        }
    }

    /**
     * Only a demo request earns the demo pitch; every other reason gets the
     * acknowledgement copy for its team.
     */
    protected function replyFor(OnboardingRequest $onboardingRequest): BaseNotification
    {
        return $onboardingRequest->contact_reason === OnboardingContactReason::BookADemo
            ? new OnboardingDemoInvitationNotification($onboardingRequest)
            : new OnboardingAcknowledgementNotification($onboardingRequest);
    }
}
