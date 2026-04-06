<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('roles', 'name'),
                Rule::notIn(Role::systemRoles()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Role names may only contain lowercase letters, numbers, and underscores.',
            'name.not_in' => 'Built-in MoreTables roles cannot be recreated through this endpoint.',
        ];
    }
}
