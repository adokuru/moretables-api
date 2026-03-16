<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpoPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expo_token' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', Rule::in(['ios', 'android'])],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'expo_token.required' => 'Provide the Expo push token from the mobile application.',
            'platform.required' => 'Specify the mobile platform for this Expo push token.',
            'platform.in' => 'Platform must be either ios or android.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('platform')) {
            $this->merge([
                'platform' => strtolower($this->string('platform')->toString()),
            ]);
        }
    }
}
