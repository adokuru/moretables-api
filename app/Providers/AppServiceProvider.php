<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\MoreTablesMailChannel;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailChannel::class, MoreTablesMailChannel::class);
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $base = config('app.password_reset_frontend_url');

            if (! is_string($base) || $base === '') {
                $base = rtrim((string) config('app.url'), '/').'/reset-password';
            }

            return $base.'?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        Gate::define('viewApiDocs', function (?User $user = null): bool {
            if (! app()->isProduction()) {
                return true;
            }

            $user ??= request()?->user('sanctum');

            if (! $user) {
                return false;
            }

            return $user->hasAnyRole([
                Role::BusinessAdmin,
                Role::DevAdmin,
                Role::SuperAdmin,
            ]);
        });
    }
}
