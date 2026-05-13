<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UploadRestaurantOnboardingPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:15360'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.max' => 'Profile photo may not be larger than 15MB.',
            'photo.mimes' => 'Profile photo must be a JPEG or PNG image.',
        ];
    }
}
