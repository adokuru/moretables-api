<?php

namespace App\Services;

use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemUserService
{
    public function forNoShowAutomation(): User
    {
        $email = (string) config('reservations.no_show_system_user_email', 'automation@moretables.internal');

        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'MoreTables Automation',
                'first_name' => 'MoreTables',
                'last_name' => 'Automation',
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'status' => UserStatus::Active,
                'auth_method' => UserAuthMethod::Password,
            ],
        );
    }
}
