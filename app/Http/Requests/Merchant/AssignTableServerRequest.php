<?php

namespace App\Http\Requests\Merchant;

use App\Models\Restaurant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTableServerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $restaurant = $this->route('restaurant');

        return [
            // Nullable — passing null unassigns whichever server currently has this table.
            'server_id' => [
                'nullable',
                'integer',
                Rule::exists('restaurant_servers', 'id')
                    ->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->id : null),
            ],
            'service_starts_at' => ['required', 'date'],
            'service_ends_at' => ['required', 'date', 'after:service_starts_at'],
        ];
    }
}
