<?php

namespace App\Http\Requests\Merchant;

use App\Models\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;

class SyncDiningAreaLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tables' => ['required', 'array'],
            'tables.*.id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'tables.*.layout_type' => ['required', 'string', 'max:50'],
            'tables.*.x_position' => ['required', 'numeric', 'min:0'],
            'tables.*.y_position' => ['required', 'numeric', 'min:0'],
            'tables.*.table_label' => ['required', 'string', 'max:20'],
            'tables.*.table_color' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'tables.*.chair_color' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'tables.*.rotation' => ['nullable', 'integer', 'between:0,359'],
            'tables.*.width' => ['nullable', 'integer', 'min:1'],
            'tables.*.height' => ['nullable', 'integer', 'min:1'],
            'tables.*.min_party_size' => ['nullable', 'integer', 'min:1'],
            'tables.*.max_party_size' => ['nullable', 'integer', 'min:'.RestaurantTable::DEFAULT_MAX_CAPACITY],
            'tables.*.dining_spot_id' => ['nullable', 'integer', 'exists:dining_spots,id'],
        ];
    }
}
