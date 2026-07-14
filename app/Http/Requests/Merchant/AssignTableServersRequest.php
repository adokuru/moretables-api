<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTableServersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurant = $this->route('restaurant');
        $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;

        return [
            'assignments' => ['required', 'array', 'min:1', 'max:200'],
            'assignments.*.table_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('restaurant_tables', 'id')->where('restaurant_id', $restaurantId),
            ],
            'assignments.*.server_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('restaurant_servers', 'id')->where('restaurant_id', $restaurantId),
            ],
            'service_starts_at' => ['required', 'date'],
            'service_ends_at' => ['required', 'date', 'after:service_starts_at'],
        ];
    }
}
