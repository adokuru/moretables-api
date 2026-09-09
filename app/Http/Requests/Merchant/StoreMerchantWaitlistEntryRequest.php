<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMerchantWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_contact_id' => ['nullable', 'integer', Rule::exists('guest_contacts', 'id')->where('restaurant_id', $this->route('restaurant')->id)->where('is_temporary', 0)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'preferred_starts_at' => ['required', 'date'],
            'preferred_ends_at' => ['nullable', 'date', 'after:preferred_starts_at'],
            'party_size' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'occasion' => ['nullable', 'string', 'max:100'],
            'guest_contact' => ['nullable', 'array'],
            'guest_contact.first_name' => ['required_without_all:user_id,guest_contact_id', 'string', 'max:120'],
            'guest_contact.last_name' => ['nullable', 'string', 'max:120'],
            'guest_contact.email' => ['nullable', 'email'],
            'guest_contact.phone' => ['nullable', 'string', 'max:30'],
            'guest_contact.seating_preference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
