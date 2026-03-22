<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantStaffManagementService
{
    public function __construct(protected ScopedRoleAssignmentService $scopedRoleAssignmentService) {}

    /**
     * @return Collection<int, UserRole>
     */
    public function listForRestaurant(Restaurant $restaurant): Collection
    {
        return $restaurant->userRoles()
            ->with(['user.roles', 'role.permissions', 'assignedBy'])
            ->whereHas('role', fn ($query) => $query->whereIn('name', Role::allRestaurantStaffRoles()))
            ->latest()
            ->get();
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, role: string}  $payload
     */
    public function invite(Restaurant $restaurant, array $payload, User $actor): UserRole
    {
        return DB::transaction(function () use ($restaurant, $payload, $actor): UserRole {
            $user = $this->resolveInvitedUser($payload);

            $this->ensureUserCanBeManaged($restaurant, $user);

            $assignment = $this->scopedRoleAssignmentService->syncRestaurantRole(
                user: $user,
                restaurant: $restaurant,
                roleName: $payload['role'],
                assignedBy: $actor->id,
            );

            DB::afterCommit(function () use ($user): void {
                Password::sendResetLink(['email' => $user->email]);
            });

            return $assignment->load(['user.roles', 'role.permissions', 'assignedBy']);
        });
    }

    /**
     * @param  array{role?: string, status?: string}  $payload
     */
    public function update(Restaurant $restaurant, User $staffMember, array $payload, User $actor): UserRole
    {
        $this->staffAssignmentForRestaurant($restaurant, $staffMember);

        if (array_key_exists('status', $payload)) {
            $staffMember->forceFill([
                'status' => $payload['status'],
            ])->save();
        }

        if (! array_key_exists('role', $payload)) {
            return $this->staffAssignmentForRestaurant($restaurant, $staffMember)->load([
                'user.roles',
                'role.permissions',
                'assignedBy',
            ]);
        }

        return $this->scopedRoleAssignmentService->syncRestaurantRole(
            user: $staffMember,
            restaurant: $restaurant,
            roleName: $payload['role'],
            assignedBy: $actor->id,
        )->load(['user.roles', 'role.permissions', 'assignedBy']);
    }

    public function remove(Restaurant $restaurant, User $staffMember): void
    {
        $this->staffAssignmentForRestaurant($restaurant, $staffMember);

        $this->scopedRoleAssignmentService->removeRestaurantRoles($staffMember, $restaurant);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string}  $payload
     */
    protected function resolveInvitedUser(array $payload): User
    {
        $email = Str::lower(trim($payload['email']));
        $phone = $payload['phone'] ?? null;

        $user = User::query()->where('email', $email)->first();

        if ($phone) {
            $phoneInUse = User::query()
                ->where('phone', $phone)
                ->when($user, fn ($query) => $query->whereKeyNot($user->id))
                ->exists();

            if ($phoneInUse) {
                throw ValidationException::withMessages([
                    'phone' => ['That phone number is already in use by another user.'],
                ]);
            }
        }

        if ($user && $user->requiresAdminLogin()) {
            throw ValidationException::withMessages([
                'email' => ['This user already has a MoreTables admin account and cannot be invited as restaurant staff.'],
            ]);
        }

        $name = trim($payload['first_name'].' '.$payload['last_name']);

        if (! $user) {
            return User::query()->create([
                'name' => $name,
                'first_name' => $payload['first_name'],
                'last_name' => $payload['last_name'],
                'email' => $email,
                'phone' => $phone,
                'password' => Str::password(40),
                'status' => UserStatus::Active,
                'auth_method' => UserAuthMethod::Password,
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill([
            'name' => $name,
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'phone' => $phone ?: $user->phone,
            'password' => $user->password ?: Str::password(40),
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    protected function ensureUserCanBeManaged(Restaurant $restaurant, User $user): void
    {
        if ($user->hasRole(Role::OrganizationOwner, organization: $restaurant->organization)) {
            throw ValidationException::withMessages([
                'email' => ['This user already manages the organization for this restaurant.'],
            ]);
        }

        if ($user->hasAnyRole(Role::allRestaurantStaffRoles(), restaurant: $restaurant)) {
            throw ValidationException::withMessages([
                'email' => ['This user is already assigned to the restaurant staff team.'],
            ]);
        }
    }

    protected function staffAssignmentForRestaurant(Restaurant $restaurant, User $staffMember): UserRole
    {
        return $restaurant->userRoles()
            ->with(['user.roles', 'role.permissions', 'assignedBy'])
            ->where('user_id', $staffMember->id)
            ->whereHas('role', fn ($query) => $query->whereIn('name', Role::allRestaurantStaffRoles()))
            ->firstOrFail();
    }
}
