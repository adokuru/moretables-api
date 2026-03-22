<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantStaffRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(Role::restaurantStaffRoles())],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'A first name is required to invite restaurant staff.',
            'last_name.required' => 'A last name is required to invite restaurant staff.',
            'email.required' => 'An email address is required to invite restaurant staff.',
            'role.required' => 'Please choose a role for the staff member.',
            'role.in' => 'The selected restaurant staff role is invalid.',
        ];
    }
}
