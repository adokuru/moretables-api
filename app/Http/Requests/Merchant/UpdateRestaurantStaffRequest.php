<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
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
        $restaurant = $this->route('restaurant');

        return [
            'access_config_id' => [
                'sometimes',
                'integer',
                Rule::exists(RestaurantAccessConfig::class, 'id')
                    ->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->id : null),
            ],
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
                if (! $this->has('access_config_id') && ! $this->has('status')) {
                    $validator->errors()->add('access_config_id', 'Provide an access_config_id, a status, or both when updating restaurant staff.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'access_config_id.exists' => 'The selected access config does not belong to this restaurant.',
            'status.in' => 'Staff status must be active or suspended.',
        ];
    }
}
