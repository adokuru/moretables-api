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
