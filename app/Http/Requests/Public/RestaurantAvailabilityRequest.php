<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class RestaurantAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1'],
        ];
    }
}
