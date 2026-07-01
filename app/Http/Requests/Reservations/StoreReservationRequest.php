<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'party_size' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'occasion' => ['nullable', 'string', 'max:100'],
            'accept_points' => ['nullable', 'boolean'],
            'subscribe_to_promotions' => ['nullable', 'boolean'],
        ];
    }
}
