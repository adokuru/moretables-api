<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantRewardRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasAnyRestaurantPermission(['restaurants.manage', 'marketing.manage'], $restaurant);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1', 'max:10000'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:0,6', 'distinct'],
            'times' => ['nullable', 'array'],
            'times.*' => ['date_format:H:i', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days.required' => 'Select at least one day.',
            'days.*.between' => 'Days must be between 0 (Sunday) and 6 (Saturday).',
            'times.*.date_format' => 'Times must use 24-hour HH:MM format (e.g. 09:00).',
        ];
    }
}
