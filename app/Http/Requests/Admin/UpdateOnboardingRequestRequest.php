<?php

namespace App\Http\Requests\Admin;

use App\OnboardingRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'address' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(OnboardingRequestStatus::class)],
        ];
    }
}
