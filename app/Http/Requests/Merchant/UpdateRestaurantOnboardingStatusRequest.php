<?php

namespace App\Http\Requests\Merchant;

use App\Enums\RestaurantOnboardingStep;
use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'step' => ['nullable', Rule::enum(RestaurantOnboardingStep::class)],
            'step_status' => ['required_with:step', 'string', Rule::in(['completed', 'skipped', 'in_progress'])],
            'current_step' => ['nullable', Rule::enum(RestaurantOnboardingStep::class)],
            'is_profile_published' => ['nullable', 'boolean'],
        ];
    }
}
