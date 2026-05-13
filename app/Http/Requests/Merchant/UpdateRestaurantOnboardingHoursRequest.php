<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantOnboardingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedules' => ['required', 'array'],
            'schedules.*.restaurant_meal_type_id' => ['required', 'integer'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.opens_at' => ['required', 'date_format:H:i'],
            'schedules.*.closes_at' => ['required', 'date_format:H:i', 'after:schedules.*.opens_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'schedules.*.closes_at.after' => 'Each closing time must be after the opening time.',
        ];
    }
}
