<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\MoreTablesMailChannel;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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
            $base = $notifiable instanceof User && $notifiable->requiresAdminLogin()
                ? config('app.admin_password_reset_frontend_url')
                : config('app.password_reset_frontend_url');

            if (! is_string($base) || $base === '') {
                $base = rtrim((string) config('app.url'), '/').'/reset-password';
            }

            $query = [
                'email' => $notifiable->getEmailForPasswordReset(),
            ];

            if (str_contains($base, '{token}')) {
                $base = str_replace('{token}', $token, $base);
            } elseif ($notifiable instanceof User && $notifiable->requiresAdminLogin()) {
                $base = rtrim($base, '/').'/'.$token;
            } else {
                $query['token'] = $token;
            }

            return $base.(str_contains($base, '?') ? '&' : '?').http_build_query([
                ...$query,
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

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            $openApi->secure(
                SecurityScheme::http('bearer', 'JWT')
                    ->as('bearerAuth')
                    ->setDescription('Paste Sanctum token as: Bearer {token}')
            );
        });
    }
}
