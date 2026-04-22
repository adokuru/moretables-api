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
            'rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'review_images' => ['sometimes', 'nullable', 'array', 'max:5'],
            'review_images.*' => ['image', 'max:10240'],
            'visited_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
