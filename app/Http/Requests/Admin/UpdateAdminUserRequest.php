<?php

namespace App\Http\Requests\Admin;

use App\Models\Restaurant;
use App\Models\Role;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'auth_method' => ['nullable', Rule::enum(UserAuthMethod::class)],
            'account_type' => ['sometimes', Rule::in(['customer', 'merchant', 'admin'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasRoleChanges = $this->hasAny(['account_type', 'roles']);

                if (! $hasRoleChanges) {
                    if ($this->filled('organization_id') || $this->filled('restaurant_id')) {
                        $validator->errors()->add('roles', 'Roles must be provided when changing an organization or restaurant scope.');
                    }

                    if ($this->filled('auth_method') && in_array($this->string('auth_method')->toString(), [UserAuthMethod::Passwordless->value, UserAuthMethod::Social->value], true) && filled($this->input('password'))) {
                        $validator->errors()->add('password', 'Passwords may only be provided for password-based accounts.');
                    }

                    return;
                }

                $roles = collect($this->input('roles', []))
                    ->filter(fn ($role) => is_string($role))
                    ->values()
                    ->all();
                $accountType = $this->input('account_type', $this->route('user')?->accountType());
                $authMethod = $this->resolvedAuthMethod($accountType);
                $restaurant = $this->filled('restaurant_id')
                    ? Restaurant::query()->find($this->integer('restaurant_id'))
                    : null;

                if ($restaurant && $this->filled('organization_id') && $restaurant->organization_id !== $this->integer('organization_id')) {
                    $validator->errors()->add('organization_id', 'The selected organization does not match the restaurant scope.');
                }

                if ($authMethod !== UserAuthMethod::Password->value && filled($this->input('password'))) {
                    $validator->errors()->add('password', 'Passwords may only be provided for password-based accounts.');
                }

                if (in_array($accountType, ['merchant', 'admin'], true) && $authMethod !== UserAuthMethod::Password->value) {
                    $validator->errors()->add('auth_method', 'Merchant and admin accounts must use the password auth method.');
                }

                if ($accountType === 'customer') {
                    if ($roles !== [] && $roles !== [Role::Customer]) {
                        $validator->errors()->add('roles', 'Customer accounts may only use the customer role.');
                    }

                    if ($this->filled('organization_id') || $this->filled('restaurant_id')) {
                        $validator->errors()->add('account_type', 'Customer accounts cannot be scoped to an organization or restaurant.');
                    }

                    return;
                }

                if ($accountType === 'merchant') {
                    $this->validateMerchantAssignment($validator, $roles);

                    return;
                }

                if ($accountType === 'admin') {
                    $this->validateAdminAssignment($validator, $roles);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already assigned to another user.',
            'phone.unique' => 'This phone number is already assigned to another user.',
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    protected function validateMerchantAssignment(Validator $validator, array $roles): void
    {
        if ($roles === []) {
            $validator->errors()->add('roles', 'Merchant accounts require at least one merchant role.');

            return;
        }

        if ($this->filled('restaurant_id')) {
            if (count($roles) !== 1 || array_diff($roles, Role::restaurantStaffRoles()) !== []) {
                $validator->errors()->add('roles', 'Restaurant assignments must use exactly one restaurant role: principal_admin, operations, analytics_reporting, marketing_growth, or guest_relations.');
            }

            return;
        }

        if (! $this->filled('organization_id')) {
            $validator->errors()->add('organization_id', 'Merchant accounts must include an organization or restaurant scope.');

            return;
        }

        if (count($roles) !== 1 || array_diff($roles, [Role::OrganizationOwner]) !== []) {
            $validator->errors()->add('roles', 'Organization assignments must use exactly one organization_owner role.');
        }
    }

    /**
     * @param  list<string>  $roles
     */
    protected function validateAdminAssignment(Validator $validator, array $roles): void
    {
        if ($roles === []) {
            $validator->errors()->add('roles', 'Admin accounts require at least one admin role.');
        }

        if (array_diff($roles, Role::adminRoles()) !== []) {
            $validator->errors()->add('roles', 'Global assignments are limited to the MoreTables admin roles.');
        }

        if ($this->filled('organization_id') || $this->filled('restaurant_id')) {
            $validator->errors()->add('account_type', 'Admin accounts cannot be scoped to an organization or restaurant.');
        }
    }

    protected function resolvedAuthMethod(?string $accountType): string
    {
        $authMethod = $this->input('auth_method');

        if (is_string($authMethod) && $authMethod !== '') {
            return $authMethod;
        }

        if ($accountType === 'customer') {
            return UserAuthMethod::Passwordless->value;
        }

        return UserAuthMethod::Password->value;
    }
}
