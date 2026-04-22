<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationGuestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.attendee_name' => ['required', 'string', 'max:200'],
            'guests.*.email_address' => ['required', 'email', 'max:255'],
            'guests.*.phone_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
