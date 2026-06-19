<?php

use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureMerchantAccess;
use App\Http\Middleware\EnsureMerchantBillingActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    })->create();
