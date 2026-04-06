<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRoleRequest;
use App\Http\Requests\Admin\UpdateAdminRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin RBAC', weight: 54)]
class AdminRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $roles = Role::query()
            ->with('permissions')
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($roleQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $roleQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                }),
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return RoleResource::collection($roles)->response();
    }

    public function store(StoreAdminRoleRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();

        $role = Role::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $permissionIds = collect($validated['permissions'] ?? [])
            ->map(fn (string $permissionName) => (int) Permission::query()->where('name', $permissionName)->value('id'))
            ->filter()
            ->values()
            ->all();

        $role->permissions()->sync($permissionIds);

        return response()->json([
            'message' => 'Role created successfully.',
            'role' => RoleResource::make($role->load('permissions')),
        ], 201);
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->ensureAdminAccess($request);

        return RoleResource::make($role->load('permissions'));
    }

    public function update(UpdateAdminRoleRequest $request, Role $role): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $role->name = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $role->description = $validated['description'];
        }

        $role->save();

        if (array_key_exists('permissions', $validated)) {
            $permissionIds = collect($validated['permissions'])
                ->map(fn (string $permissionName) => (int) Permission::query()->where('name', $permissionName)->value('id'))
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($permissionIds);
        }

        return response()->json([
            'message' => 'Role updated successfully.',
            'role' => RoleResource::make($role->refresh()->load('permissions')),
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensureAdminAccess($request);

        abort_if($role->userRoles()->exists(), 422, 'This role is currently assigned to one or more users.');

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }
}
