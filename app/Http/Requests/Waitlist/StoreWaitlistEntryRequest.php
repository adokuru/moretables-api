<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'preferred_starts_at' => ['required', 'date', 'after:now'],
            'preferred_ends_at' => ['nullable', 'date', 'after:preferred_starts_at'],
            'party_size' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
