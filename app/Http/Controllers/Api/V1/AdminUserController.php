<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Users', weight: 54)]
class AdminUserController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[QueryParameter('search', type: 'string', example: 'ada@example.com')]
    #[QueryParameter('status', type: 'string', example: 'active')]
    #[QueryParameter('role', type: 'string', example: 'operations')]
    #[QueryParameter('account_type', type: 'string', example: 'merchant')]
    #[Response(200, type: 'array{data: list<UserResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $users = User::query()
            ->with([
                'roles',
                'roleAssignments.role',
                'roleAssignments.organization',
                'roleAssignments.restaurant',
            ])
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
            ->when(
                filled($request->string('account_type')->toString()),
                fn ($query) => $this->applyAccountTypeFilter($query, $request->string('account_type')->toString()),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => UserResource::collection($users->getCollection())->resolve($request),
            'links' => [
                'first' => $users->url(1),
                'last' => $users->url($users->lastPage()),
                'prev' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $users->currentPage(),
                'from' => $users->firstItem(),
                'last_page' => $users->lastPage(),
                'path' => $users->path(),
                'per_page' => $users->perPage(),
                'to' => $users->lastItem(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $user = User::query()->create([
            ...$this->extractUserAttributes($validated, creating: true),
            'email_verified_at' => now(),
            'last_active_at' => now(),
        ]);
        $this->syncUserAssignments($user, $validated, $request->user());

        return response()->json([
            'message' => 'User created successfully.',
            'user' => UserResource::make($this->loadUserRelations($user)),
        ], 201);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureAdminAccess($request);

        return UserResource::make($this->loadUserRelations($user));
    }

    public function update(UpdateAdminUserRequest $request, User $user): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $user->fill($this->extractUserAttributes($validated));

        if (array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            $user->name = trim(implode(' ', array_filter([
                $validated['first_name'] ?? $user->first_name,
                $validated['last_name'] ?? $user->last_name,
            ])));
        }

        $user->save();
        $this->syncUserAssignments($user, $validated, $request->user());

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => UserResource::make($this->loadUserRelations($user->refresh())),
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

    protected function applyAccountTypeFilter($query, string $accountType)
    {
        return match ($accountType) {
            'admin' => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', Role::adminRoles())),
            'merchant' => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', Role::merchantRoles())),
            'customer' => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', Role::customerRoles())),
            default => $query,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function extractUserAttributes(array $validated, bool $creating = false): array
    {
        $attributes = [];

        foreach (['first_name', 'last_name', 'email', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if ($creating || array_key_exists('password', $validated)) {
            if (($validated['password'] ?? null) !== null && $validated['password'] !== '') {
                $attributes['password'] = $validated['password'];
            } elseif ($creating) {
                $attributes['password'] = null;
            }
        }

        if ($creating || array_key_exists('auth_method', $validated) || array_key_exists('account_type', $validated)) {
            $attributes['auth_method'] = $validated['auth_method']
                ?? (($validated['account_type'] ?? null) === 'customer'
                    ? UserAuthMethod::Passwordless
                    : UserAuthMethod::Password);
        }

        if ($creating && ! array_key_exists('status', $attributes)) {
            $attributes['status'] = UserStatus::Active;
        }

        if ($creating || array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            $attributes['name'] = trim(implode(' ', array_filter([
                $validated['first_name'] ?? null,
                $validated['last_name'] ?? null,
            ])));
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function syncUserAssignments(User $user, array $validated, User $actor): void
    {
        if (! array_key_exists('account_type', $validated) && ! array_key_exists('roles', $validated)) {
            return;
        }

        UserRole::query()->where('user_id', $user->id)->delete();

        $roleNames = $this->requestedRoleNames($validated);

        if ($roleNames === []) {
            return;
        }

        $restaurant = ! empty($validated['restaurant_id'])
            ? Restaurant::query()->find($validated['restaurant_id'])
            : null;
        $organization = $restaurant?->organization;

        if (! $organization && ! empty($validated['organization_id'])) {
            $organization = Organization::query()->find($validated['organization_id']);
        }

        foreach ($roleNames as $roleName) {
            $roleId = Role::query()->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $roleId,
                'scope_type' => $restaurant
                    ? 'restaurant'
                    : ($organization ? 'organization' : null),
                'organization_id' => $organization?->id,
                'restaurant_id' => $restaurant?->id,
                'assigned_by' => $actor->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    protected function requestedRoleNames(array $validated): array
    {
        if (($validated['account_type'] ?? null) === 'customer') {
            return [Role::Customer];
        }

        return collect($validated['roles'] ?? [])
            ->filter(fn ($role) => is_string($role))
            ->unique()
            ->values()
            ->all();
    }

    protected function loadUserRelations(User $user): User
    {
        return $user->load([
            'roles',
            'roleAssignments.role',
            'roleAssignments.organization',
            'roleAssignments.restaurant',
        ]);
    }
}
