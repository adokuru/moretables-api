<?php

namespace App\Http\Requests\Merchant;

use App\Models\RestaurantTable;
use App\TableShape;
use App\TableStatus;
use App\TableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
            'name' => ['required', 'string', 'max:120'],
            'min_capacity' => ['nullable', 'integer', 'min:1'],
            'max_capacity' => ['nullable', 'integer', 'min:'.RestaurantTable::DEFAULT_MAX_CAPACITY, 'gte:min_capacity'],
            'table_type' => ['sometimes', Rule::enum(TableType::class)],
            'shape' => ['sometimes', Rule::enum(TableShape::class)],
            'status' => ['nullable', Rule::enum(TableStatus::class)],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'x_position' => ['nullable', 'numeric', 'min:0'],
            'y_position' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'rotation' => ['nullable', 'integer', 'between:0,359'],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
        ];
    }
}
