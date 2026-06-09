<?php

namespace App\Http\Requests\AvailabilityAlert;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'whatsapp_updates' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'The alert date cannot be in the past.',
            'window_end.after' => 'The end of the time window must be after the start.',
        ];
    }
}
