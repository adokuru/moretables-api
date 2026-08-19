<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;

class UploadRestaurantOnboardingPhotoRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    public function rules(): array
    {
        // Must not exceed config('media-library.max_file_size') (10MB) — Spatie
        // throws an uncaught FileTooBig exception (a 500, not a clean
        // validation error) for anything past that, regardless of what's
        // allowed here, so this can never be raised above it.
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.max' => 'Profile photo may not be larger than 10MB.',
            'photo.mimes' => 'Profile photo must be a JPEG or PNG image.',
        ];
    }
}
