<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use App\Http\Requests\Merchant\Concerns\ValidatesRestaurantSpecialDayShifts;
use App\Models\RestaurantSpecialDay;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRestaurantSpecialDayRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;
    use ValidatesRestaurantSpecialDayShifts;

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail) use ($restaurantId): void {
                    if (RestaurantSpecialDay::query()
                        ->where('restaurant_id', $restaurantId)
                        ->where('date', $value)
                        ->exists()) {
                        $fail('A special day already exists for this date.');
                    }
                },
            ],
            'is_closed' => ['sometimes', 'boolean'],
            'shifts' => ['exclude_if:is_closed,true', 'required_unless:is_closed,true', 'array', 'min:1'],
            'shifts.*.restaurant_meal_type_id' => [
                'required_with:shifts',
                'integer',
                Rule::exists('restaurant_meal_types', 'id')->where('restaurant_id', $restaurantId),
            ],
            'shifts.*.opens_at' => ['required_with:shifts', 'date_format:H:i'],
            'shifts.*.closes_at' => ['required_with:shifts', 'date_format:H:i', 'after:shifts.*.opens_at'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateNonOverlappingShifts($validator),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shifts.required_unless' => 'At least one shift is required when the special day is open.',
            'shifts.min' => 'At least one shift is required when the special day is open.',
            'shifts.*.closes_at.after' => 'Each closing time must be after the opening time.',
        ];
    }
}
