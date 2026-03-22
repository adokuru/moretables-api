<?php

namespace App\Http\Requests\Admin;

use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
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
