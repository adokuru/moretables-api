<?php

namespace App\Services;

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingDemoInvitationNotification;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\UserStatus;
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

        if (filled($onboardingRequest->email)) {
            Notification::route('mail', $onboardingRequest->email)
                ->notify(new OnboardingDemoInvitationNotification($onboardingRequest));
        }
    }
}
