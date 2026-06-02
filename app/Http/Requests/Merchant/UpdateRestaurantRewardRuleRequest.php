<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantRewardRuleRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'points' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'days' => ['sometimes', 'array', 'min:1'],
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
            'days.*.between' => 'Days must be between 0 (Sunday) and 6 (Saturday).',
            'times.*.date_format' => 'Times must use 24-hour HH:MM format (e.g. 09:00).',
        ];
    }
}
