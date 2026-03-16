<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\ReservationSource;

class StoreMerchantReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'starts_at' => ['required', 'date'],
            'party_size' => ['required', 'integer', 'min:1'],
            'source' => ['required', Rule::enum(ReservationSource::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:500'],
            'guest_contact' => ['nullable', 'array'],
            'guest_contact.first_name' => ['required_without:user_id', 'string', 'max:120'],
            'guest_contact.last_name' => ['nullable', 'string', 'max:120'],
            'guest_contact.email' => ['nullable', 'email'],
            'guest_contact.phone' => ['required_without:user_id', 'string', 'max:30'],
        ];
    }
}
