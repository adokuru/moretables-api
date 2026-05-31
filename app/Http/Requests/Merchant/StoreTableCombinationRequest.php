<?php

namespace App\Http\Requests\Merchant;

use App\Models\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'table_ids.*' => ['integer', 'distinct'],
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
            'min_capacity' => ['required', 'integer', 'min:1'],
            'max_capacity' => ['required', 'integer', 'min:1', 'gte:min_capacity'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $restaurant = $this->route('restaurant');
            $tableIds = collect($this->input('table_ids', []))
                ->map(fn ($tableId) => (int) $tableId)
                ->unique()
                ->values();

            if (! $restaurant || $tableIds->isEmpty()) {
                return;
            }

            $diningAreaId = $this->filled('dining_area_id') ? $this->integer('dining_area_id') : null;
            $matchingTables = RestaurantTable::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereIn('id', $tableIds)
                ->when($diningAreaId, fn ($query) => $query->where('dining_area_id', $diningAreaId))
                ->count();

            if ($matchingTables !== $tableIds->count()) {
                $validator->errors()->add(
                    'table_ids',
                    'All selected tables must exist in this restaurant and dining area.'
                );
            }
        });
    }
}
