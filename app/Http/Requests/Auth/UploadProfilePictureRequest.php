<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UploadProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_picture' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
