<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTableCombinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_ids' => ['required', 'array', 'min:2'],
            'table_ids.*' => ['integer'],
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
            'min_capacity' => ['required', 'integer', 'min:1'],
            'max_capacity' => ['required', 'integer', 'min:1', 'gte:min_capacity'],
        ];
    }
}
