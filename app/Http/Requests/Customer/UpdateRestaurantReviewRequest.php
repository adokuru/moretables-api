<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['prohibited'],
            'food_rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'service_rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'ambience_rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'value_rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'review_images' => ['sometimes', 'nullable', 'array', 'max:5'],
            'review_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'visited_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
