<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use App\Models\RestaurantSpecialDay;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantSpecialDayRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail) use ($restaurantId): void {
                    if (RestaurantSpecialDay::query()
                        ->where('restaurant_id', $restaurantId)
                        ->whereDate('date', $value)
                        ->exists()) {
                        $fail('A special day already exists for this date.');
                    }
                },
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
