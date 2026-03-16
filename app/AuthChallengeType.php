<?php

namespace App;

enum AuthChallengeType: string
{
    case GuestSignup = 'guest_signup';
    case StaffLogin = 'staff_login';
}
