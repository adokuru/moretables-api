<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ConfirmPasswordChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:4'],
            'password' => ['required', 'string', Password::min(8)],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'The verification code must be 4 digits.',
            'password_confirmation.same' => 'The password confirmation does not match.',
        ];
    }
}
