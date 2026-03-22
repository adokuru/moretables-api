<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\HasMediaUploadFields;
use App\RestaurantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantRequest extends FormRequest
{
    use HasMediaUploadFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string'],
            'website' => ['nullable', 'url', 'max:2048'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'average_price_range' => ['nullable', 'string', 'max:100'],
            'dining_style' => ['nullable', 'string', 'max:100'],
            'dress_code' => ['nullable', 'string', 'max:100'],
            'total_seating_capacity' => ['nullable', 'integer', 'min:1'],
            'number_of_tables' => ['nullable', 'integer', 'min:1'],
            'menu_source' => ['nullable', Rule::in(['link', 'pdf', 'manual'])],
            'menu_link' => ['nullable', 'url', 'max:2048'],
            'menu_document' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:20480'],
            'payment_options' => ['nullable', 'array'],
            'payment_options.*' => ['string', 'max:50'],
            'accessibility_features' => ['nullable', 'array'],
            'accessibility_features.*' => ['string', 'max:120'],
            'status' => ['nullable', Rule::enum(RestaurantStatus::class)],
            'cuisines' => ['nullable', 'array'],
            'cuisines.*' => ['string', 'max:100'],
            'hours' => ['nullable', 'array'],
            'hours.*.day_of_week' => ['required_with:hours', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
            'policy' => ['nullable', 'array'],
            'policy.reservation_duration_minutes' => ['nullable', 'integer', 'min:30'],
            'policy.booking_window_days' => ['nullable', 'integer', 'min:1'],
            'policy.cancellation_cutoff_hours' => ['nullable', 'integer', 'min:0'],
            'policy.min_party_size' => ['nullable', 'integer', 'min:1'],
            'policy.max_party_size' => ['nullable', 'integer', 'gte:policy.min_party_size'],
            'policy.deposit_required' => ['nullable', 'boolean'],
            ...$this->mediaUploadRules(),
        ];
    }
}
