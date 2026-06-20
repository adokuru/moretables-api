<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Models\BillingPlan;
use App\Models\CuisineOption;
use App\Models\DiningArea;
use App\Models\MerchantSubscription;
use App\Models\OnboardingRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantGalleryCategory;
use App\Models\RestaurantHour;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantReview;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SavedRestaurant;
use App\Models\User;
use App\Models\UserRestaurantListItem;
use App\Models\UserRole;
use App\Models\WaitlistEntry;
use App\Notifications\Channels\MoreTablesMailChannel;
use App\Observers\AuthorizationCacheObserver;
use App\Observers\RestaurantObserver;
use App\Observers\RestaurantPublicDataObserver;
use App\Services\Payments\PaystackPaymentProvider;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        Restaurant::observe(RestaurantPublicDataObserver::class);
        RestaurantReview::observe(RestaurantPublicDataObserver::class);
        SavedRestaurant::observe(RestaurantPublicDataObserver::class);
        UserRestaurantListItem::observe(RestaurantPublicDataObserver::class);
        RestaurantTable::observe(RestaurantPublicDataObserver::class);
        RestaurantHour::observe(RestaurantPublicDataObserver::class);
        RestaurantAvailabilitySchedule::observe(RestaurantPublicDataObserver::class);
        RestaurantAvailabilityPeriod::observe(RestaurantPublicDataObserver::class);
        RestaurantPolicy::observe(RestaurantPublicDataObserver::class);
        DiningArea::observe(RestaurantPublicDataObserver::class);
        RestaurantMenuItem::observe(RestaurantPublicDataObserver::class);
        RestaurantGalleryCategory::observe(RestaurantPublicDataObserver::class);
        Reservation::observe(RestaurantPublicDataObserver::class);
        WaitlistEntry::observe(RestaurantPublicDataObserver::class);
        CuisineOption::observe(RestaurantPublicDataObserver::class);
        MerchantSubscription::observe(RestaurantPublicDataObserver::class);
        BillingPlan::observe(RestaurantPublicDataObserver::class);
        OnboardingRequest::observe(RestaurantPublicDataObserver::class);
        Organization::observe(RestaurantPublicDataObserver::class);
        Media::observe(RestaurantPublicDataObserver::class);

        UserRole::observe(AuthorizationCacheObserver::class);
        RestaurantAccessConfig::observe(AuthorizationCacheObserver::class);
        RolePermission::observe(AuthorizationCacheObserver::class);

        $this->configureRateLimiters();

        DB::whenQueryingForLongerThan(
            (int) config('performance.monitoring.query_time_warning_milliseconds'),
            function (Connection $connection, QueryExecuted $event): void {
                Log::warning('Cumulative database query time threshold exceeded.', [
                    'connection' => $connection->getName(),
                    'sql' => $event->sql,
                    'time_ms' => $event->time,
                ]);
            },
        );

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

    private function configureRateLimiters(): void
    {
        RateLimiter::for('public-read', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.public_reads'),
        )->by($request->ip()));

        RateLimiter::for('public-expensive', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.public_expensive'),
        )->by($request->ip()));

        RateLimiter::for('public-write', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.public_writes'),
        )->by($request->ip()));

        RateLimiter::for('auth-initiate', function (Request $request): array {
            $identity = Str::lower((string) (
                $request->input('email')
                ?? $request->input('phone')
                ?? $request->input('identifier')
                ?? $request->ip()
            ));

            return [
                Limit::perMinute((int) config('performance.rate_limits.otp_identity'))->by("identity:{$identity}"),
                Limit::perMinute((int) config('performance.rate_limits.otp_ip'))->by("ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('auth-verify', function (Request $request): Limit {
            $challenge = (string) ($request->input('challenge_token') ?? 'missing');

            return Limit::perMinute((int) config('performance.rate_limits.otp_verification'))
                ->by("challenge:{$challenge}:ip:{$request->ip()}");
        });

        RateLimiter::for('customer-api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.customer_api'),
        )->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('merchant-api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.merchant_api'),
        )->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('admin-api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('performance.rate_limits.admin_api'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
