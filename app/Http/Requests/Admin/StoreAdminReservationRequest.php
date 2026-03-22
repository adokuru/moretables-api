<?php

namespace App\Http\Requests\Admin;

use App\Models\GuestContact;
use App\Models\RestaurantTable;
use App\ReservationSource;
use App\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdminReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:guest_contact_id'],
            'guest_contact_id' => ['nullable', 'integer', 'exists:guest_contacts,id', 'required_without:user_id'],
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'source' => ['required', Rule::enum(ReservationSource::class)],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'party_size' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $restaurantId = $this->integer('restaurant_id');

            if ($this->filled('restaurant_table_id')) {
                $table = RestaurantTable::query()->find($this->integer('restaurant_table_id'));

                if ($table && $table->restaurant_id !== $restaurantId) {
                    $validator->errors()->add('restaurant_table_id', 'The selected table does not belong to the restaurant.');
                }
            }

            if ($this->filled('guest_contact_id')) {
                $guestContact = GuestContact::query()->find($this->integer('guest_contact_id'));

                if ($guestContact && $guestContact->restaurant_id !== null && $guestContact->restaurant_id !== $restaurantId) {
                    $validator->errors()->add('guest_contact_id', 'The selected guest contact does not belong to the restaurant.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'user_id.required_without' => 'Select a user or a guest contact for the reservation.',
            'guest_contact_id.required_without' => 'Select a guest contact or a user for the reservation.',
        ];
    }
}
