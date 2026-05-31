<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use App\Http\Requests\Merchant\Concerns\ValidatesRestaurantSpecialDayShifts;
use App\Models\RestaurantSpecialDay;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRestaurantSpecialDayRequest extends FormRequest
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
        $specialDayId = $this->route('specialDay')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'date' => [
                'sometimes',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail) use ($restaurantId, $specialDayId): void {
                    if (RestaurantSpecialDay::query()
                        ->where('restaurant_id', $restaurantId)
                        ->where('date', $value)
                        ->whereKeyNot($specialDayId)
                        ->exists()) {
                        $fail('A special day already exists for this date.');
                    }
                },
            ],
            'is_closed' => ['sometimes', 'boolean'],
            'shifts' => ['exclude_if:is_closed,true', 'sometimes', 'array', 'min:1'],
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
            function (Validator $validator): void {
                $this->validateNonOverlappingShifts($validator);

                /** @var ?RestaurantSpecialDay $specialDay */
                $specialDay = $this->route('specialDay');
                $isClosed = $this->has('is_closed')
                    ? $this->boolean('is_closed')
                    : (bool) $specialDay?->is_closed;

                if (! $isClosed && ! $this->has('shifts') && ! $specialDay?->shifts()->exists()) {
                    $validator->errors()->add('shifts', 'At least one shift is required when the special day is open.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shifts.min' => 'At least one shift is required when the special day is open.',
            'shifts.*.closes_at.after' => 'Each closing time must be after the opening time.',
        ];
    }
}
