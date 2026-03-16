<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\ReservationStatus;

class UpdateMerchantReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'starts_at' => ['sometimes', 'date'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
