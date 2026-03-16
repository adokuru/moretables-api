<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin RBAC', weight: 54)]
class AdminUserRoleController extends Controller
{
    public function update(UpdateUserRolesRequest $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();

        UserRole::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $validated['organization_id'] ?? null)
            ->where('restaurant_id', $validated['restaurant_id'] ?? null)
            ->delete();

        $roles = Role::query()->whereIn('name', $validated['roles'])->get(['id', 'name']);

        foreach ($roles as $role) {
            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => isset($validated['restaurant_id']) ? 'restaurant' : (isset($validated['organization_id']) ? 'organization' : null),
                'organization_id' => $validated['organization_id'] ?? null,
                'restaurant_id' => $validated['restaurant_id'] ?? null,
                'assigned_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'message' => 'User roles updated successfully.',
            'user' => UserResource::make($user->refresh()->load('roles')),
        ]);
    }
}
