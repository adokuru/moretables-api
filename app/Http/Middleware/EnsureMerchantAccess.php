<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantAccess
{
    /**
     * Coarse defense-in-depth gate for the merchant route group.
     *
     * Per-restaurant permission checks (hasRestaurantPermission) remain
     * authoritative; this only prevents tokens with no restaurant-facing role
     * (e.g. customers) from reaching merchant controllers at all.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->hasAnyRole(Role::restaurantAccessRoles()),
            403,
        );

        return $next($request);
    }
}
