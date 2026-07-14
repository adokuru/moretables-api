<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;

class ListRestaurantServersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasRestaurantPermission('reservations.view', $restaurant);
    }

    public function rules(): array
    {
        return [
            'service_starts_at' => ['required', 'date'],
            'service_ends_at' => ['required', 'date', 'after:service_starts_at'],
        ];
    }
}
