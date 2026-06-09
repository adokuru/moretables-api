<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    /**
     * Coarse defense-in-depth gate for the admin route group.
     *
     * Per-endpoint role/permission checks remain authoritative; this only
     * prevents non-privileged tokens (e.g. customers) from reaching admin
     * controllers at all. OrganizationOwner is permitted because select admin
     * endpoints (such as audit logs) intentionally allow that role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->hasAnyRole([...Role::adminRoles(), Role::OrganizationOwner]),
            403,
        );

        return $next($request);
    }
}
