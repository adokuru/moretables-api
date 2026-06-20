<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasRestaurantPermission('reservations.manage', $restaurant);
    }

    public function rules(): array
    {
        $restaurant = $this->route('restaurant');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('restaurant_servers', 'name')
                    ->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->id : null),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A server name is required.',
            'name.unique' => 'A server with this name already exists for the restaurant.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
