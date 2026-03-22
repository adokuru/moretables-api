<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use App\Models\Role;
use App\UserStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasRestaurantPermission('staff.manage', $restaurant);
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in(Role::restaurantStaffRoles())],
            'status' => ['sometimes', Rule::in([
                UserStatus::Active->value,
                UserStatus::Suspended->value,
            ])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('role') && ! $this->has('status')) {
                    $validator->errors()->add('role', 'Provide a role, a status, or both when updating restaurant staff.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'The selected restaurant staff role is invalid.',
            'status.in' => 'Staff status must be active or suspended.',
        ];
    }
}
