<?php

namespace App\Http\Requests\Merchant;

use App\Enums\RestaurantOnboardingStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantOnboardingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
