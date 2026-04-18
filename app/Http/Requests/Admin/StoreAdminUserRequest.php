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

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'string', 'max:120'],
            'last_name' => ['required_without:name', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'auth_method' => ['nullable', Rule::enum(UserAuthMethod::class)],
            'account_type' => ['nullable', Rule::in(['customer', 'merchant', 'admin'])],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];
        $name = trim((string) $this->input('name', ''));

        if ($name !== '') {
            $nameParts = preg_split('/\s+/', $name) ?: [];
            $firstName = array_shift($nameParts) ?? '';
            $lastName = trim(implode(' ', $nameParts));

            if (! $this->filled('first_name') && $firstName !== '') {
                $payload['first_name'] = $firstName;
            }

            if (! $this->filled('last_name')) {
                $payload['last_name'] = $lastName !== '' ? $lastName : $firstName;
            }
        }

        if ($this->filled('role') && ! $this->filled('roles')) {
            $payload['roles'] = [$this->string('role')->toString()];
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $roles = collect($this->input('roles', []))
                    ->filter(fn ($role) => is_string($role))
                    ->values()
                    ->all();
                $accountType = $this->inferredAccountType();
                $authMethod = $this->resolvedAuthMethod($accountType);
                $restaurant = $this->filled('restaurant_id')
                    ? Restaurant::query()->find($this->integer('restaurant_id'))
                    : null;

                if ($restaurant && $this->filled('organization_id') && $restaurant->organization_id !== $this->integer('organization_id')) {
                    $validator->errors()->add('organization_id', 'The selected organization does not match the restaurant scope.');
                }

                if ($authMethod === UserAuthMethod::Password->value && blank($this->input('password')) && $accountType !== 'admin') {
                    $validator->errors()->add('password', 'A password is required for password-based accounts.');
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

                    return;
                }

                if ($roles === []) {
                    if ($this->filled('organization_id') || $this->filled('restaurant_id')) {
                        $validator->errors()->add('roles', 'A role is required when assigning an organization or restaurant scope.');
                    }

                    return;
                }

                if ($this->filled('restaurant_id') || $this->filled('organization_id')) {
                    $this->validateMerchantAssignment($validator, $roles);

                    return;
                }

                if ($roles === [Role::Customer]) {
                    return;
                }

                $this->validateAdminAssignment($validator, $roles);
            },
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already assigned to another user.',
            'first_name.required_without' => 'A name is required.',
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

        return $accountType === 'customer'
            ? UserAuthMethod::Passwordless->value
            : UserAuthMethod::Password->value;
    }

    protected function inferredAccountType(): ?string
    {
        $accountType = $this->input('account_type');

        if (is_string($accountType) && $accountType !== '') {
            return $accountType;
        }

        $roles = collect($this->input('roles', []))
            ->filter(fn ($role): bool => is_string($role))
            ->values()
            ->all();

        if ($roles === [] && $this->filled('role')) {
            $roles = [$this->string('role')->toString()];
        }

        if ($roles !== [] && array_diff($roles, Role::adminRoles()) === []) {
            return 'admin';
        }

        if ($roles !== [] && array_diff($roles, Role::merchantRoles()) === []) {
            return 'merchant';
        }

        if ($roles === [Role::Customer]) {
            return 'customer';
        }

        return null;
    }
}
