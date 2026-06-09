<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['prohibited'],
            'food_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'service_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'ambience_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'value_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
            'review_images' => ['nullable', 'array', 'max:10'],
            'review_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'visited_at' => ['nullable', 'date'],
        ];
    }
}
