<?php

namespace App\Http\Requests\Admin;

use App\Models\GuestContact;
use App\Models\RestaurantTable;
use App\ReservationSource;
use App\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'guest_contact_id' => ['nullable', 'integer', 'exists:guest_contacts,id'],
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'source' => ['sometimes', Rule::enum(ReservationSource::class)],
            'status' => ['sometimes', Rule::enum(ReservationStatus::class)],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reservation = $this->route('reservation');
            $restaurantId = $reservation?->restaurant_id;

            if (! $reservation) {
                return;
            }

            if ($this->filled('restaurant_table_id')) {
                $table = RestaurantTable::query()->find($this->integer('restaurant_table_id'));

                if ($table && $table->restaurant_id !== $restaurantId) {
                    $validator->errors()->add('restaurant_table_id', 'The selected table does not belong to the reservation restaurant.');
                }
            }

            if ($this->filled('guest_contact_id')) {
                $guestContact = GuestContact::query()->find($this->integer('guest_contact_id'));

                if ($guestContact && $guestContact->restaurant_id !== null && $guestContact->restaurant_id !== $restaurantId) {
                    $validator->errors()->add('guest_contact_id', 'The selected guest contact does not belong to the reservation restaurant.');
                }
            }
        });
    }
}
