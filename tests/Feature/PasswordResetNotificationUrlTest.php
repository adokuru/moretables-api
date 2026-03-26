<?php

use App\Models\User;
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
