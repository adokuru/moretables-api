<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRestaurantPermission('reservations.view', $this->route('restaurant'));
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['required', 'integer', 'min:1'],
            'dining_area_id' => ['nullable', 'integer', Rule::exists('dining_areas', 'id')->where('restaurant_id', $this->route('restaurant')->id)],
        ];
    }

    public function messages(): array
    {
        return ['dining_area_id.exists' => 'Choose a seating area belonging to this restaurant.'];
    }
}
