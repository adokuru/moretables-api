<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var Role|null $role */
                $role = $this->route('role');

                if (! $role) {
                    return;
                }

                if ($role && in_array($role->name, Role::systemRoles(), true) && $this->filled('name') && $this->string('name')->toString() !== $role->name) {
                    $validator->errors()->add('name', 'Built-in MoreTables roles cannot be renamed.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Role names may only contain lowercase letters, numbers, and underscores.',
        ];
    }
}
