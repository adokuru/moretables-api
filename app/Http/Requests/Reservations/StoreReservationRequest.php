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
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
            'notes' => ['nullable', 'string'],
            'occasion' => ['nullable', 'string', 'max:100'],
            'accept_points' => ['nullable', 'boolean'],
            'subscribe_to_promotions' => ['nullable', 'boolean'],
            'card_hold_reference' => ['nullable', 'string'],
        ];
    }
}
