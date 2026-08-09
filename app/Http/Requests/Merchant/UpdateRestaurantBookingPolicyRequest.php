<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantBookingPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasAnyRestaurantPermission(['restaurants.manage', 'policies.manage'], $restaurant);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'booking_details_locale' => ['sometimes', 'string', 'max:10'],
            'custom_dining_policy' => ['nullable', 'string', 'min:100', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_dining_policy.min' => 'Custom dining policy must be at least 100 characters.',
            'custom_dining_policy.max' => 'Custom dining policy may not exceed 1000 characters.',
        ];
    }
}
