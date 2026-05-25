<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\MoreTablesMailChannel;
use App\Observers\RestaurantObserver;
use App\Services\Payments\PaystackPaymentProvider;
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
        $this->app->bind(PaymentProvider::class, PaystackPaymentProvider::class);
    }

    public function boot(): void
    {
        Restaurant::observe(RestaurantObserver::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $audience = match (true) {
                $notifiable instanceof User && $notifiable->requiresAdminLogin() => 'admin',
                $notifiable instanceof User && $notifiable->requiresStaffLogin() => 'restaurant',
                default => 'main',
            };

            $frontendUrl = config('app.frontend_urls.'.$audience);
            $path = config('app.frontend_paths.password_reset.'.$audience);

            if (is_string($frontendUrl) && $frontendUrl !== '') {
                $base = rtrim($frontendUrl, '/').'/'.ltrim((string) $path, '/');
            } else {
                $base = rtrim((string) config('app.url'), '/').'/reset-password';
            }

            $query = [
                'email' => $notifiable->getEmailForPasswordReset(),
            ];

            if (str_contains($base, '{token}')) {
                $base = str_replace('{token}', $token, $base);
            } elseif (in_array($audience, ['admin', 'restaurant'], true)) {
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
