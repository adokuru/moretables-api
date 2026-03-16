<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class RevokeExpoPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expo_token' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'expo_token.required' => 'Provide the Expo push token that should be revoked.',
        ];
    }
}
