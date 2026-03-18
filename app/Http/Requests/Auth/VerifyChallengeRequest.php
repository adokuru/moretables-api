<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'uuid'],
            'code' => ['required', 'digits:4'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
