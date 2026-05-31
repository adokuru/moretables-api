<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantSpecialDayRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $restaurantId = $this->route('restaurant')?->id;
        $specialDayId = $this->route('specialDay')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'date' => [
                'sometimes',
                'date_format:Y-m-d',
                Rule::unique('restaurant_special_days', 'date')
                    ->where('restaurant_id', $restaurantId)
                    ->ignore($specialDayId),
            ],
            'is_closed' => ['sometimes', 'boolean'],
            'shifts' => ['exclude_if:is_closed,true', 'sometimes', 'array'],
            'shifts.*.restaurant_meal_type_id' => ['required_with:shifts', 'integer'],
            'shifts.*.opens_at' => ['required_with:shifts', 'date_format:H:i'],
            'shifts.*.closes_at' => ['required_with:shifts', 'date_format:H:i', 'after:shifts.*.opens_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.unique' => 'A special day already exists for this date.',
            'shifts.*.closes_at.after' => 'Each closing time must be after the opening time.',
        ];
    }
}
