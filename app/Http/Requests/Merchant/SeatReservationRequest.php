<?php

namespace App\Http\Requests\Merchant;

use App\ReservationServiceStage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SeatReservationRequest extends FormRequest
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
        return [
            'restaurant_table_id' => [
                'sometimes',
                'prohibits:table_combination_id,restaurant_table_ids',
                'integer',
                'exists:restaurant_tables,id',
            ],
            'table_combination_id' => [
                'sometimes',
                'prohibits:restaurant_table_id,restaurant_table_ids',
                'integer',
                'exists:table_combinations,id',
            ],
            'restaurant_table_ids' => [
                'sometimes',
                'prohibits:restaurant_table_id,table_combination_id',
                'array',
                'min:2',
            ],
            'restaurant_table_ids.*' => ['integer', 'distinct', 'exists:restaurant_tables,id'],
            'service_stage' => ['nullable', Rule::in([
                ReservationServiceStage::PartiallySeated->value,
                ReservationServiceStage::Seated->value,
            ])],
        ];
    }
}
