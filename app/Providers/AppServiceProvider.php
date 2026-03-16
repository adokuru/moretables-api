<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
