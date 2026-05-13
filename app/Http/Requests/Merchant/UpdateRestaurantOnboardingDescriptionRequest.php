<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantOnboardingDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:100', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Description must be at least 100 characters.',
            'description.max' => 'Description may not be longer than 1000 characters.',
        ];
    }
}
