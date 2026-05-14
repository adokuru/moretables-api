<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRestaurantOnboardingStatusRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    public function rules(): array
    {
        return [
            'is_profile_published' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['step', 'step_status', 'current_step'] as $field) {
                    if ($this->has($field)) {
                        $validator->errors()->add($field, 'This onboarding status field is managed by the server.');
                    }
                }
            },
        ];
    }
}
