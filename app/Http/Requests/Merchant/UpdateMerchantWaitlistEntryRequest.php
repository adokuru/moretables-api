<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_starts_at' => ['sometimes', 'date'],
            'preferred_ends_at' => ['nullable', 'date', 'after:preferred_starts_at'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'occasion' => ['nullable', 'string', 'max:100'],
            'guest_contact' => ['nullable', 'array'],
            'guest_contact.first_name' => ['nullable', 'string', 'max:120'],
            'guest_contact.last_name' => ['nullable', 'string', 'max:120'],
            'guest_contact.email' => ['nullable', 'email'],
            'guest_contact.phone' => ['nullable', 'string', 'max:30'],
            'guest_contact.seating_preference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
