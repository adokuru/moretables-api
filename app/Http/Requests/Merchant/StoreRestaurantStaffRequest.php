<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
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
        $restaurant = $this->route('restaurant');

        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'access_config_id' => [
                'required',
                'integer',
                Rule::exists(RestaurantAccessConfig::class, 'id')
                    ->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->id : null),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'A first name is required to invite restaurant staff.',
            'last_name.required' => 'A last name is required to invite restaurant staff.',
            'email.required' => 'An email address is required to invite restaurant staff.',
            'access_config_id.required' => 'Please choose an access config for the staff member.',
            'access_config_id.exists' => 'The selected access config does not belong to this restaurant.',
        ];
    }
}
