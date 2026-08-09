<?php

use App\Exceptions\PaymentProviderException;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureMerchantAccess;
use App\Http\Middleware\EnsureMerchantBillingActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if (str_starts_with((string) env('CACHE_LIMITER_STORE', env('CACHE_STORE', 'database')), 'redis')) {
            $middleware->throttleWithRedis();
        }

        $middleware->alias([
            'merchant.billing.active' => EnsureMerchantBillingActive::class,
            'admin.access' => EnsureAdminAccess::class,
            'merchant.access' => EnsureMerchantAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (PaymentProviderException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 502);
            }

            return null;
        });

        // abort($code) / abort_if() / abort_unless() called with no message string
        // (the overwhelming majority of call sites throughout this app — every
        // per-permission hasRestaurantPermission() check, for instance) throws an
        // HttpException with an *empty* message. Laravel's default JSON rendering
        // then sends {"message": ""}, and the frontend's toast (message ??
        // fallback) never falls back on that — "??" only catches null/undefined,
        // not "" — so the user sees a genuinely blank error toast. This backfills
        // a sensible default per status code purely when the message is empty;
        // any call site that already passes its own message (e.g. abort_if($cond,
        // 403, 'You cannot remove yourself...')) is left completely untouched.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($exception->getMessage() !== '') {
                return null;
            }

            $fallbackMessages = [
                401 => 'You need to be signed in to do that.',
                403 => "You don't have permission to perform this action.",
                404 => 'The requested resource could not be found.',
                405 => 'That action is not supported.',
                419 => 'Your session has expired. Please refresh and try again.',
                429 => 'Too many requests. Please try again shortly.',
            ];
            $status = $exception->getStatusCode();

            return response()->json([
                'message' => $fallbackMessages[$status] ?? 'Something went wrong. Please try again.',
            ], $status, $exception->getHeaders());
        });
    })->create();
