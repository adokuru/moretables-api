<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\HasMediaUploadFields;
use App\RestaurantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRestaurantRequest extends FormRequest
{
    use HasMediaUploadFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantId = $this->route('restaurant')?->id;

        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('restaurants', 'slug')->ignore($restaurantId)],
            'status' => ['nullable', Rule::enum(RestaurantStatus::class)],
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
            ...$this->mediaUploadRules(),
        ];
    }
}
