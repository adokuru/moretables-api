<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'visited_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.between' => 'Ratings must be between 1 and 5.',
        ];
    }
}
