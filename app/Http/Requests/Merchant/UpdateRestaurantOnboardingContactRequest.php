<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantOnboardingContactRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:2048'],
            'average_price_range' => ['nullable', 'string', 'max:100'],
            'primary_cuisine_option_id' => ['nullable', 'integer', Rule::exists('cuisine_options', 'id')],
            'additional_cuisine_option_ids' => ['nullable', 'array'],
            'additional_cuisine_option_ids.*' => ['integer', Rule::exists('cuisine_options', 'id')],
            'social_handles' => ['nullable', 'array'],
            'social_handles.*.platform' => ['required_with:social_handles', 'string', 'max:32'],
            'social_handles.*.handle' => ['required_with:social_handles', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'primary_cuisine_option_id.exists' => 'The selected cuisine option does not exist.',
            'additional_cuisine_option_ids.*.exists' => 'One or more selected additional cuisines do not exist.',
        ];
    }
}
