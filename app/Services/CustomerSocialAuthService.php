<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserRole;
use App\SocialAuthProvider;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerSocialAuthService
{
    public function __construct(protected SocialIdentityVerifier $socialIdentityVerifier) {}

    /**
     * @param  array{id_token: string, device_name?: string, first_name?: string|null, last_name?: string|null}  $attributes
     * @return array{user: User, token: string}
     */
    public function authenticate(SocialAuthProvider $provider, array $attributes): array
    {
        $identity = $this->socialIdentityVerifier->verify($provider, $attributes['id_token']);

        return DB::transaction(function () use ($provider, $attributes, $identity): array {
            $socialAccount = SocialAccount::query()
                ->with('user.roles')
                ->where('provider', $provider->value)
                ->where('provider_user_id', $identity->providerUserId)
                ->first();

            $user = $socialAccount?->user;

            if (! $user) {
                if (! $identity->email) {
                    throw ValidationException::withMessages([
                        'id_token' => ['This social account did not provide an email address. Retry the provider sign-in flow with email access enabled.'],
                    ]);
                }

                if (! $identity->emailVerified) {
                    throw ValidationException::withMessages([
                        'id_token' => ['This social account email address is not verified.'],
                    ]);
                }

                $user = User::query()
                    ->with('roles')
                    ->where('email', $identity->email)
                    ->first();
            }

            if ($user && $user->requiresAdminLogin()) {
                throw ValidationException::withMessages([
                    'id_token' => ['Use the admin login endpoint for this account.'],
                ]);
            }

            if ($user && $user->requiresStaffLogin()) {
                throw ValidationException::withMessages([
                    'id_token' => ['Use the staff login endpoint for this account.'],
                ]);
            }

            if (! $user) {
                $user = $this->createCustomerUser($identity, $attributes);
            } else {
                $this->hydrateCustomerUser($user, $identity, $attributes);
            }

            $this->assignCustomerRole($user);

            SocialAccount::query()->updateOrCreate(
                [
                    'provider' => $provider->value,
                    'provider_user_id' => $identity->providerUserId,
                ],
                [
                    'user_id' => $user->id,
                    'provider_email' => $identity->email,
                    'last_used_at' => now(),
                ],
            );

            $token = $user->createToken($attributes['device_name'] ?? $provider->value.'-auth')->plainTextToken;

            return [
                'user' => $user->refresh(),
                'token' => $token,
            ];
        });
    }

    /**
     * @param  array{first_name?: string|null, last_name?: string|null}  $attributes
     */
    protected function createCustomerUser(VerifiedSocialIdentity $identity, array $attributes): User
    {
        $firstName = $attributes['first_name'] ?? $identity->firstName;
        $lastName = $attributes['last_name'] ?? $identity->lastName;
        $email = $identity->email;

        return User::query()->create([
            'name' => $this->displayName($firstName, $lastName, $email),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => null,
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Social,
            'email_verified_at' => now(),
            'last_active_at' => now(),
        ]);
    }

    /**
     * @param  array{first_name?: string|null, last_name?: string|null}  $attributes
     */
    protected function hydrateCustomerUser(User $user, VerifiedSocialIdentity $identity, array $attributes): void
    {
        $firstName = $attributes['first_name'] ?? $identity->firstName ?? $user->first_name;
        $lastName = $attributes['last_name'] ?? $identity->lastName ?? $user->last_name;

        $user->forceFill([
            'name' => $this->displayName($firstName, $lastName, $user->email, $user->name),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Social,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'last_active_at' => now(),
        ])->save();
    }

    protected function displayName(?string $firstName, ?string $lastName, string $email, ?string $fallback = null): string
    {
        $name = trim(implode(' ', array_filter([$firstName, $lastName])));

        if ($name !== '') {
            return $name;
        }

        return $fallback ?: $email;
    }

    protected function assignCustomerRole(User $user): void
    {
        $roleId = Role::query()->where('name', Role::Customer)->value('id');

        if (! $roleId) {
            return;
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'organization_id' => null,
            'restaurant_id' => null,
        ], [
            'scope_type' => null,
            'assigned_by' => $user->id,
        ]);
    }
}
