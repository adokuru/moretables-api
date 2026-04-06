<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.max' => 'Your first name may not be greater than 120 characters.',
            'last_name.max' => 'Your last name may not be greater than 120 characters.',
            'bio.max' => 'Your bio may not be greater than 1000 characters.',
            'birthday.date' => 'Your birthday must be a valid date.',
            'birthday.before_or_equal' => 'Your birthday must be today or earlier.',
        ];
    }
}
