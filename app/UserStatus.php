<?php

namespace App;

enum UserStatus: string
{
    case PendingEmailVerification = 'pending_email_verification';
    case PendingProfileCompletion = 'pending_profile_completion';
    case Active = 'active';
    case Suspended = 'suspended';
}
