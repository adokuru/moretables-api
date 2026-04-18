<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends password reset without the password.reset web route', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'reset-test@example.com']);

    $status = Password::sendResetLink(['email' => $user->email]);

    expect($status)->toBe(Password::RESET_LINK_SENT);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $built = $notification->toMail($user);

        return str_contains((string) $built->actionUrl, 'token=')
            && str_contains((string) $built->actionUrl, 'email=');
    });
});

it('uses password_reset_frontend_url when configured', function (): void {
    config(['app.password_reset_frontend_url' => 'https://app.example.com/password/reset']);

    Notification::fake();

    $user = User::factory()->create(['email' => 'custom-url@example.com']);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $built = $notification->toMail($user);

        return str_starts_with((string) $built->actionUrl, 'https://app.example.com/password/reset?');
    });
});

it('uses the admin password reset frontend url for admin accounts', function (): void {
    config(['app.admin_password_reset_frontend_url' => 'https://admin.moretables.com/change-password']);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create(['email' => 'admin-reset@example.com']);
    assignScopedRole($admin, Role::BusinessAdmin);

    Password::sendResetLink(['email' => $admin->email]);

    Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use ($admin): bool {
        $built = $notification->toMail($admin);

        return str_starts_with((string) $built->actionUrl, 'https://admin.moretables.com/change-password/')
            && str_contains((string) $built->actionUrl, 'email=admin-reset%40example.com')
            && ! str_contains((string) $built->actionUrl, 'token=');
    });
});
