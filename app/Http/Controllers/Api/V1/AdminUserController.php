<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Users', weight: 54)]
class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $users = User::query()
            ->with('roles')
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($userQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $userQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                }),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                filled($request->string('role')->toString()),
                fn ($query) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', $request->string('role')->toString())),
            )
            ->latest()
            ->paginate($this->perPage($request));

        return UserResource::collection($users)->response();
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $user = User::query()->create([
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'status' => $validated['status'] ?? UserStatus::Active,
            'auth_method' => $validated['auth_method'] ?? UserAuthMethod::Password,
            'email_verified_at' => now(),
            'last_active_at' => now(),
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => UserResource::make($user->load('roles')),
        ], 201);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureAdminAccess($request);

        return UserResource::make($user->load('roles'));
    }

    public function update(UpdateAdminUserRequest $request, User $user): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $user->fill($validated);

        if (array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            $user->name = trim(implode(' ', array_filter([
                $validated['first_name'] ?? $user->first_name,
                $validated['last_name'] ?? $user->last_name,
            ])));
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => UserResource::make($user->refresh()->load('roles')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureAdminAccess($request);

        abort_if($request->user()->is($user), 422, 'You cannot delete the authenticated admin account.');

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
