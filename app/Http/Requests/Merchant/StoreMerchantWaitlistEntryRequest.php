<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'preferred_starts_at' => ['required', 'date'],
            'preferred_ends_at' => ['nullable', 'date', 'after:preferred_starts_at'],
            'party_size' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'guest_contact' => ['nullable', 'array'],
            'guest_contact.first_name' => ['required_without:user_id', 'string', 'max:120'],
            'guest_contact.last_name' => ['nullable', 'string', 'max:120'],
            'guest_contact.email' => ['nullable', 'email'],
            'guest_contact.phone' => ['required_without:user_id', 'string', 'max:30'],
        ];
    }
}
