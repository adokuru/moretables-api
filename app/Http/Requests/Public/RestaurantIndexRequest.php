<?php

namespace App\Http\Requests\Public;

use App\AveragePriceRange;
use App\TableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantIndexRequest extends FormRequest
{
    public const SORTS = ['featured', 'distance', 'newest', 'rating'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'cuisine' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'price' => ['nullable', 'string', Rule::enum(AveragePriceRange::class)],
            'seating' => ['nullable', 'string', Rule::enum(TableType::class)],
            'latitude' => ['nullable', 'numeric', 'required_with:longitude', Rule::requiredIf($this->input('sort') === 'distance')],
            'longitude' => ['nullable', 'numeric', 'required_with:latitude', Rule::requiredIf($this->input('sort') === 'distance')],
            'radius_km' => ['nullable', 'numeric', 'min:0.1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('price')) {
            $this->merge(['price' => AveragePriceRange::normalize($this->input('price'))]);
        }
    }

    public function messages(): array
    {
        return [
            'latitude.required_with' => 'Latitude is required when longitude is provided.',
            'longitude.required_with' => 'Longitude is required when latitude is provided.',
        ];
    }
}
