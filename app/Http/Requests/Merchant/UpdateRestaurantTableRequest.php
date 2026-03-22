<?php

namespace App\Http\Requests\Merchant;

use App\TableStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'min_capacity' => ['nullable', 'integer', 'min:1'],
            'max_capacity' => ['sometimes', 'integer', 'min:1', 'gte:min_capacity'],
            'status' => ['nullable', Rule::enum(TableStatus::class)],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
