<?php

namespace App\Http\Requests\Admin;

use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'auth_method' => ['nullable', Rule::enum(UserAuthMethod::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already assigned to another user.',
            'phone.unique' => 'This phone number is already assigned to another user.',
        ];
    }
}
