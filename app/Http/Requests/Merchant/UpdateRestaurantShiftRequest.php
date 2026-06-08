<?php

namespace App\Http\Requests\Merchant;

use App\Enums\RestaurantShiftTurnControlReleasePolicy;
use App\Enums\RestaurantShiftTurnControlRuleType;
use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use App\TableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantShiftRequest extends FormRequest
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
            'restaurant_meal_type_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('restaurant_meal_types', 'id')->where('restaurant_id', $restaurantId),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'day_of_week' => ['sometimes', 'integer', 'between:0,6'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i', 'after:starts_at'],
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'turn_times' => ['sometimes', 'array'],
            'turn_times.*.party_size' => ['required_with:turn_times', 'integer', 'min:1'],
            'turn_times.*.duration_minutes' => ['required_with:turn_times', 'integer', 'min:15', 'max:480'],
            'table_availability' => ['sometimes', 'array'],
            'table_availability.*.dining_area_id' => [
                'nullable',
                'integer',
                Rule::exists('dining_areas', 'id')->where('restaurant_id', $restaurantId),
            ],
            'table_availability.*.table_type' => ['nullable', Rule::enum(TableType::class)],
            'table_availability.*.include_combinations' => ['sometimes', 'boolean'],
            'table_availability.*.is_reservable' => ['sometimes', 'boolean'],
            'turn_controls' => ['sometimes', 'array'],
            'turn_controls.*.rule_type' => ['required_with:turn_controls', Rule::enum(RestaurantShiftTurnControlRuleType::class)],
            'turn_controls.*.party_size' => ['nullable', 'integer', 'min:1'],
            'turn_controls.*.restaurant_table_id' => [
                'nullable',
                'integer',
                Rule::exists('restaurant_tables', 'id')->where('restaurant_id', $restaurantId),
            ],
            'turn_controls.*.min_turns' => ['required_with:turn_controls', 'integer', 'min:1'],
            'flow_controls' => ['sometimes', 'array'],
            'flow_controls.interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'flow_controls.default_max_covers' => ['sometimes', 'integer', 'min:1'],
            'flow_controls.release_policy' => ['sometimes', Rule::enum(RestaurantShiftTurnControlReleasePolicy::class)],
            'flow_controls.release_hours_before' => ['nullable', 'integer', 'min:0', 'max:168'],
            'flow_controls.intervals' => ['sometimes', 'array'],
            'flow_controls.intervals.*.starts_at' => ['required_with:flow_controls.intervals', 'date_format:H:i'],
            'flow_controls.intervals.*.max_covers' => ['required_with:flow_controls.intervals', 'integer', 'min:1'],
        ];
    }
}
