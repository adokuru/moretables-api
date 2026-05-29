<?php

namespace App\Services;

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\UserStatus;
use Illuminate\Support\Facades\Notification;

class OnboardingRequestNotificationService
{
    public function notifyAdmins(OnboardingRequest $onboardingRequest): void
    {
        $admins = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', Role::adminRoles()))
            ->get();

        Notification::send($admins, new OnboardingRequestSubmittedNotification($onboardingRequest));
    }
}
