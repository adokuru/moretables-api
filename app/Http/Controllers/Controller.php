<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected function ensureAdminAccess(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(Role::adminRoles()), 403);
    }

    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    protected function authenticatedUserFromToken(Request $request): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user;
        }

        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken?->tokenable instanceof User) {
            return null;
        }

        return $accessToken->tokenable;
    }
}
