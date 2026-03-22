<?php

namespace App\Http\Requests\Admin;

use App\Models\Restaurant;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $roles = collect($this->input('roles', []))
                    ->filter(fn ($role) => is_string($role))
                    ->values()
                    ->all();

                if ($this->filled('restaurant_id')) {
                    if (count($roles) !== 1 || array_diff($roles, Role::restaurantStaffRoles()) !== []) {
                        $validator->errors()->add('roles', 'Restaurant assignments must use exactly one restaurant role: principal_admin, operations, analytics_reporting, marketing_growth, or guest_relations.');
                    }

                    $restaurant = Restaurant::query()->find($this->integer('restaurant_id'));

                    if ($restaurant && $this->filled('organization_id') && $restaurant->organization_id !== $this->integer('organization_id')) {
                        $validator->errors()->add('organization_id', 'The selected organization does not match the restaurant scope.');
                    }

                    return;
                }

                if ($this->filled('organization_id')) {
                    if (count($roles) !== 1 || array_diff($roles, [Role::OrganizationOwner]) !== []) {
                        $validator->errors()->add('roles', 'Organization assignments must use exactly one organization_owner role.');
                    }

                    return;
                }

                if (array_diff($roles, Role::adminRoles()) !== []) {
                    $validator->errors()->add('roles', 'Global assignments are limited to the MoreTables admin roles.');
                }
            },
        ];
    }
}
