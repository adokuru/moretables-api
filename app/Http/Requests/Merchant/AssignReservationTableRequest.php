<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class AssignReservationTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_table_id' => ['required', 'integer', 'exists:restaurant_tables,id'],
        ];
    }
}
