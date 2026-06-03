<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function logAdminAudit(
        Request $request,
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?Restaurant $restaurant = null,
        ?Organization $organization = null,
    ): void {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return;
        }

        app(AuditLogService::class)->logAdmin(
            action: $action,
            actor: $actor,
            auditable: $auditable,
            oldValues: $oldValues,
            newValues: $newValues,
            restaurant: $restaurant,
            organization: $organization,
            description: $description,
        );
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
