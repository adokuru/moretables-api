<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantOnboardingInternalNotesRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    public function rules(): array
    {
        return [
            'skip' => ['sometimes', 'boolean'],
            'internal_notes' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('skip')),
                Rule::prohibitedIf(fn (): bool => $this->boolean('skip')),
                'string',
                'min:100',
                'max:10000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'internal_notes.min' => 'Internal notes must be at least 100 characters.',
            'internal_notes.max' => 'Internal notes may not be longer than 10000 characters.',
            'internal_notes.prohibited' => 'Internal notes may not be provided when skipping this step.',
        ];
    }
}
